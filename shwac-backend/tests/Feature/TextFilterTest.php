<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Services\Operations\BookingService;
use Database\Seeders\ServiceCatalogSeeder;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Text "contains" filter for free-text answers (Model & Serial No, Inverter Brand / Serial…). */
class TextFilterTest extends TestCase
{
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        (new ServiceCatalogSeeder)->run();
        $svc = app(BookingService::class);
        $ac = Service::where('code', 'HA-AC')->firstOrFail();
        $pv = Service::where('code', 'SOL-PV')->firstOrFail();
        $acForm = ['service_type' => 'Repair', 'warranty_status' => 'AMC', 'defect_code' => 'Noise'];
        $pvForm = ['solar_service_type' => 'Breakdown', 'system_capacity' => '3 KW', 'system_type' => 'On-Grid'];
        $mk = fn ($service, array $form, string $priority = 'medium') => $svc->create($this->customer(), $service, [
            'scheduled_date' => today()->toDateString(), 'priority' => $priority, 'form_data' => $form,
        ])->id;

        $this->ids = [
            'lg' => $mk($ac, $acForm + ['model_serial' => 'LG-15-001', 'brand' => 'LG'], 'high'),
            'lg2' => $mk($ac, $acForm + ['model_serial' => 'lg 15 002', 'brand' => 'LG']),
            'daikin' => $mk($ac, $acForm + ['model_serial' => 'DKN-9X', 'brand' => 'Daikin']),
            'ac_none' => $mk($ac, $acForm),
            'pv_growatt' => $mk($pv, $pvForm + ['inverter_brand_serial' => 'Growatt SN-7781', 'subsidy_id' => 'SUB-2024-0091']),
            'pv_other' => $mk($pv, $pvForm + ['inverter_brand_serial' => 'Luminous LX-20']),
        ];
        $this->actingAsUser($this->staff('super_admin'));
    }

    private function bookings(string $query): array
    {
        return collect($this->getJson('/api/v1/admin/bookings?'.$query)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
    }

    private function jobs(string $query): array
    {
        return collect($this->getJson('/api/v1/admin/jobs?'.$query)->assertOk()->json('data'))->pluck('booking_id')->sort()->values()->all();
    }

    private function only(string ...$keys): array
    {
        return collect($keys)->map(fn ($k) => $this->ids[$k])->sort()->values()->all();
    }

    #[Test]
    public function serial_contains_any_case(): void
    {
        $this->assertSame($this->only('lg'), $this->bookings('form_text[model_serial]=LG-15'));
        $this->assertSame($this->only('lg'), $this->bookings('form_text[model_serial]=lg-15-001'));      // case does not matter
        $this->assertSame($this->only('lg', 'lg2'), $this->bookings('form_text[model_serial]=lg'));
        $this->assertSame($this->only('lg2'), $this->bookings('form_text[model_serial]=15%20002'));      // spaces inside work
        $this->assertSame($this->only('daikin'), $this->bookings('form_text[model_serial]=9x'));
        $this->assertSame([], $this->bookings('form_text[model_serial]=XYZ'));
    }

    #[Test]
    public function solar_inverter_brand_serial_and_subsidy(): void
    {
        $this->assertSame($this->only('pv_growatt'), $this->bookings('form_text[inverter_brand_serial]=growatt'));
        $this->assertSame($this->only('pv_growatt'), $this->bookings('form_text[inverter_brand_serial]=SN-7781'));
        $this->assertSame($this->only('pv_other'), $this->bookings('form_text[inverter_brand_serial]=LX'));
        $this->assertSame($this->only('pv_growatt'), $this->bookings('form_text[subsidy_id]=2024'));
    }

    #[Test]
    public function jobs_use_the_same_text_filter(): void
    {
        $this->assertSame($this->only('lg', 'lg2'), $this->jobs('form_text[model_serial]=LG'));
        $this->assertSame($this->only('pv_growatt'), $this->jobs('form_text[inverter_brand_serial]=Growatt'));
    }

    #[Test]
    public function wildcards_blank_and_unsafe_input_are_handled(): void
    {
        $all = count($this->bookings(''));
        $this->assertSame([], $this->bookings('form_text[model_serial]=%25'));           // "%" alone: not "match anything"
        $this->assertSame([], $this->bookings('form_text[model_serial]=_'));             // "_" alone: not a one-letter wildcard
        $this->assertSame([], $this->bookings('form_text[model_serial]=LG-%25-001'));    // % removed → "lg--001", matches nothing
        $this->assertSame($all, count($this->bookings('form_text[model_serial]=')));     // empty: ignored
        $this->assertSame($all, count($this->bookings('form_text[model_serial]=%20%20'))); // only spaces: ignored
        $this->assertSame($all, count($this->bookings("form_text[x')%20OR%201=1--]=LG"))); // unsafe key: ignored
        $this->assertSame([], $this->bookings('form_text[brand_does_not_exist]=LG'));     // unknown key: no answers match
        $this->getJson('/api/v1/admin/bookings?form_text[model_serial]='.str_repeat('a', 61))->assertStatus(422);
        $this->getJson('/api/v1/admin/bookings?form_text[model_serial][]=LG')->assertStatus(422);
        $this->getJson('/api/v1/admin/jobs?form_text=LG')->assertStatus(422);
    }

    #[Test]
    public function text_filter_combines_with_other_filters(): void
    {
        $this->assertSame($this->only('lg'), $this->bookings('form_text[model_serial]=lg&priority=high'));
        $this->assertSame($this->only('lg', 'lg2'), $this->bookings('form_text[model_serial]=lg&form[brand]=LG'));
        $this->assertSame([], $this->bookings('form_text[model_serial]=lg&form[brand]=Daikin'));
        $ac = Service::where('code', 'HA-AC')->value('id');
        $this->assertSame($this->only('lg', 'lg2'), $this->bookings("form_text[model_serial]=lg&service_id={$ac}"));
    }

    #[Test]
    public function existing_filters_behave_exactly_as_before(): void
    {
        // Connected-function check: nothing sent → all rows; old filters unchanged.
        $this->assertSame(collect($this->ids)->sort()->values()->all(), $this->bookings(''));
        $this->assertSame($this->only('lg'), $this->bookings('priority=high'));
        $this->assertSame($this->only('daikin'), $this->bookings('form[brand]=Daikin'));
        $this->assertSame($this->only('pv_growatt', 'pv_other'), $this->bookings('form[system_type]=On-Grid'));
        $this->getJson('/api/v1/admin/bookings?from=bad-date')->assertStatus(422);
        $this->getJson('/api/v1/admin/bookings?form_min[tds]=abc')->assertStatus(422);
    }
}
