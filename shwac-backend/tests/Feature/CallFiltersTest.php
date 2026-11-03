<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceJob;
use App\Models\User;
use App\Services\Operations\BookingService;
use App\Services\Operations\JobService;
use Database\Seeders\ServiceCatalogSeeder;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** V2 step 3: filter Bookings and Service jobs by service, priority, technician, date, place and service answers. */
class CallFiltersTest extends TestCase
{
    private array $ids = [];

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        (new ServiceCatalogSeeder)->run();
        $bookings = app(BookingService::class);
        $ac = Service::where('code', 'HA-AC')->firstOrFail();
        $swh = Service::where('code', 'SOL-SWH')->firstOrFail();

        $meerut = $this->customer();
        $agra = $this->customer();
        $agra->addresses()->first()->update(['city' => 'Agra', 'pincode' => '282001']);

        $this->ids['ac_amc_high'] = $bookings->create($meerut, $ac, ['scheduled_date' => '2026-12-01', 'priority' => 'high', 'form_data' => [
            'service_type' => 'Gas Filling', 'warranty_status' => 'AMC', 'defect_code' => 'Not Cooling', 'tonnage' => '1.5 Ton',
        ]])->id;
        $this->ids['ac_out_low'] = $bookings->create($agra, $ac, ['scheduled_date' => '2026-12-10', 'priority' => 'low', 'form_data' => [
            'service_type' => 'Repair', 'warranty_status' => 'Out of Warranty', 'defect_code' => 'Noise', 'tonnage' => '2 Ton',
        ]])->id;
        $this->ids['swh'] = $bookings->create($meerut, $swh, ['scheduled_date' => '2026-12-05', 'form_data' => [
            // System Type (tube/plate) is mandatory for the water heater per the client spec.
            'solar_service_type' => 'Acid Wash / Descaling', 'system_capacity' => '200 LPD', 'system_type' => 'ETC (Glass Tube)',
        ]])->id;

        $this->tech = $this->technician();
        app(JobService::class)->assign(ServiceJob::where('booking_id', $this->ids['ac_amc_high'])->firstOrFail(), $this->tech);

        $this->actingAsUser($this->staff('super_admin'));
    }

    private function bookings(array $query): array
    {
        return collect($this->getJson('/api/v1/admin/bookings?'.http_build_query($query))->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
    }

    private function jobs(array $query): array
    {
        return collect($this->getJson('/api/v1/admin/jobs?'.http_build_query($query))->assertOk()->json('data'))->pluck('booking_id')->sort()->values()->all();
    }

    private function only(string ...$keys): array
    {
        return collect($keys)->map(fn ($k) => $this->ids[$k])->sort()->values()->all();
    }

    #[Test]
    public function bookings_filter_by_category_service_priority_and_technician(): void
    {
        $solar = ServiceCategory::where('slug', 'solar')->value('id');
        $ac = Service::where('code', 'HA-AC')->value('id');

        $this->assertSame($this->only('swh'), $this->bookings(['category_id' => $solar]));
        $this->assertSame($this->only('ac_amc_high', 'ac_out_low'), $this->bookings(['service_id' => $ac]));
        $this->assertSame($this->only('ac_amc_high'), $this->bookings(['priority' => 'high']));
        $this->assertSame($this->only('swh'), $this->bookings(['priority' => 'medium']));
        $this->assertSame($this->only('ac_amc_high'), $this->bookings(['technician_id' => $this->tech->id]));
    }

    #[Test]
    public function bookings_filter_by_service_answers_date_and_place(): void
    {
        $this->assertSame($this->only('ac_amc_high'), $this->bookings(['form' => ['warranty_status' => 'AMC']]));
        $this->assertSame($this->only('ac_out_low'), $this->bookings(['form' => ['service_type' => 'Repair', 'tonnage' => '2 Ton']]));
        $this->assertSame([], $this->bookings(['form' => ['service_type' => 'Repair', 'tonnage' => '1.5 Ton']]));
        $this->assertSame($this->only('swh'), $this->bookings(['form' => ['system_capacity' => '200 LPD']]));
        $this->assertSame($this->only('ac_amc_high', 'swh'), $this->bookings(['from' => '2026-12-01', 'to' => '2026-12-05']));
        $this->assertSame($this->only('ac_out_low'), $this->bookings(['place' => 'agra']));
        $this->assertSame($this->only('ac_out_low'), $this->bookings(['place' => '282001']));
    }

    #[Test]
    public function jobs_use_the_same_filters_through_their_booking(): void
    {
        $this->assertSame($this->only('ac_amc_high'), $this->jobs(['priority' => 'high']));
        $this->assertSame($this->only('ac_amc_high'), $this->jobs(['form' => ['warranty_status' => 'AMC']]));
        $this->assertSame($this->only('ac_amc_high'), $this->jobs(['technician_id' => $this->tech->id]));
        $this->assertSame($this->only('swh'), $this->jobs(['category_id' => ServiceCategory::where('slug', 'solar')->value('id')]));
        $this->assertSame($this->only('ac_out_low'), $this->jobs(['place' => 'Agra']));
        $this->getJson('/api/v1/admin/jobs?priority=high')->assertOk()->assertJsonPath('data.0.booking.priority', 'high');
    }

    #[Test]
    public function unsafe_filter_keys_are_ignored(): void
    {
        $all = $this->only('ac_amc_high', 'ac_out_low', 'swh');
        $this->assertSame($all, $this->bookings(['form' => ["x') OR 1=1 --" => 'AMC']]));
        $this->assertSame($all, $this->bookings(['form' => ['warranty_status' => '']]));
        $this->assertSame([], $this->bookings(['priority' => 'urgent']));
    }
}
