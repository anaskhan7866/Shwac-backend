<?php

namespace Database\Seeders;

use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * V2 service catalog: two categories (Home Appliances, Solar) with one service per
 * product and the fields from the client's "Master Add Call Fields" spec.
 * Fields marked "(Opt)" in the spec are optional; everything else is required.
 *
 * Safe to run again: existing services keep their admin-edited price and fields.
 * Old categories are removed; old services that are already used by bookings,
 * jobs or invoices are kept (inactive) under the new categories so history stays intact.
 */
class ServiceCatalogSeeder extends Seeder
{
    /** Visiting charge from the spec ("₹ 350 (Visiting Charge)"), excl. GST. Editable in admin. */
    private const VISIT_CHARGE = 350;

    public function run(): void
    {
        $appliances = ServiceCategory::updateOrCreate(['slug' => 'appliances'], ['name' => 'Home Appliances', 'sort_order' => 1, 'is_active' => true]);
        $solar = ServiceCategory::updateOrCreate(['slug' => 'solar'], ['name' => 'Solar', 'sort_order' => 2, 'is_active' => true]);

        $catalog = [
            [$appliances, 'HA-AC', 'Air Conditioner (AC)', $this->airConditioner()],
            [$appliances, 'HA-WM', 'Washing Machine', $this->washingMachine()],
            [$appliances, 'HA-FRIDGE', 'Refrigerator', $this->refrigerator()],
            [$appliances, 'HA-RO', 'RO Water Purifier', $this->roPurifier()],
            [$appliances, 'HA-TV', 'Television (TV)', $this->television()],
            [$solar, 'SOL-PV', 'Solar Rooftop Panel (PV)', $this->solarPanel()],
            [$solar, 'SOL-SWH', 'Solar Water Heater', $this->solarWaterHeater()],
            [$solar, 'SOL-INV', 'Solar Inverter', $this->solarInverter()],
        ];

        $codes = [];
        foreach ($catalog as $i => [$cat, $code, $name, $fields]) {
            $codes[] = $code;
            Service::firstOrCreate(['code' => $code], [
                'service_category_id' => $cat->id, 'name' => $name, 'base_price' => self::VISIT_CHARGE,
                'tax_rate' => 18, 'sac_code' => '998719', 'duration_minutes' => 60,
                'description' => 'Visiting charge. Parts and repair work are billed after the technician checks.',
                'form_schema' => $fields, 'sort_order' => $i, 'is_active' => true,
            ]);
        }

        $this->retireOldCatalog([$appliances->id, $solar->id], $codes, $appliances, $solar);
    }

    private function retireOldCatalog(array $keepCategoryIds, array $keepCodes, ServiceCategory $appliances, ServiceCategory $solar): void
    {
        DB::transaction(function () use ($keepCategoryIds, $keepCodes, $appliances, $solar) {
            foreach (Service::with('category')->whereNotIn('code', $keepCodes)->get() as $old) {
                if ($this->isUsed($old)) {
                    $isSolar = str_contains(strtolower($old->category?->slug ?? ''), 'solar') || str_starts_with($old->code, 'SOL');
                    $old->update(['is_active' => false, 'service_category_id' => $isSolar ? $solar->id : $appliances->id]);
                } else {
                    $old->delete();
                }
            }
            ServiceCategory::whereNotIn('id', $keepCategoryIds)->whereDoesntHave('services')->delete();
        });
    }

    private function isUsed(Service $s): bool
    {
        return DB::table('bookings')->where('service_id', $s->id)->exists()
            || DB::table('service_jobs')->where('service_id', $s->id)->exists()
            || DB::table('invoice_items')->where('item_type', 'service')->where('item_id', $s->id)->exists();
    }

    // ---- field helpers ---------------------------------------------------------

    private function select(string $key, string $label, array $options, bool $required = false): array
    {
        return ['key' => $key, 'label' => $label, 'type' => 'select', 'required' => $required, 'options' => $options];
    }

    private function multi(string $key, string $label, array $options): array
    {
        return ['key' => $key, 'label' => $label, 'type' => 'multiselect', 'required' => false, 'options' => $options];
    }

    private function text(string $key, string $label, bool $required = false): array
    {
        return ['key' => $key, 'label' => $label, 'type' => 'text', 'required' => $required];
    }

    private function number(string $key, string $label, int $min, int $max): array
    {
        return ['key' => $key, 'label' => $label, 'type' => 'number', 'required' => false, 'min' => $min, 'max' => $max];
    }

    /** Section 2 fields shared by every home appliance, in spec order. */
    private function applianceBase(array $serviceTypes, array $brands, array $defects): array
    {
        return [
            $this->select('service_type', 'Service Type', $serviceTypes, true),
            $this->select('warranty_status', 'Warranty Status', ['In-Warranty', 'Out of Warranty', 'AMC'], true),
            $this->select('defect_code', 'Complaint / Defect Code', [...$defects, 'Other', 'No issue (installation / routine service)'], true),
            $this->select('brand', 'Brand Name', [...$brands, 'Other']),
            $this->text('model_serial', 'Model & Serial No'),
        ];
    }

    private function airConditioner(): array
    {
        return [
            ...$this->applianceBase(
                ['Gas Filling', 'Installation / Fitting', 'Uninstallation', 'General Service', 'Repair'],
                ['LG', 'Samsung', 'Daikin', 'Voltas', 'Blue Star', 'Hitachi', 'Lloyd', 'Panasonic', 'Carrier'],
                ['Not Cooling', 'Water Leak', 'Noise', 'Not Turning On', 'Bad Smell', 'Remote Not Working'],
            ),
            $this->select('tonnage', 'Capacity / Tonnage', ['1 Ton', '1.5 Ton', '2 Ton', 'Other']),
            $this->select('gas_type', 'Gas Type', ['R32', 'R410A', 'R22', 'Not sure']),
            $this->select('inverter', 'Inverter / Non-Inverter', ['Inverter', 'Non-Inverter']),
            $this->select('ac_type', 'Split / Window', ['Split', 'Window']),
        ];
    }

    private function washingMachine(): array
    {
        return [
            ...$this->applianceBase(
                ['Installation / Fitting', 'Uninstallation', 'General Service', 'Repair'],
                ['LG', 'Samsung', 'Whirlpool', 'IFB', 'Bosch', 'Godrej', 'Panasonic', 'Haier'],
                ['Not Spinning', 'Water Leak', 'Noise / Vibration', 'Not Draining', 'Not Turning On', 'Error Code on Display'],
            ),
            $this->select('capacity_kg', 'Capacity (Kg)', ['6 Kg', '6.5 Kg', '7 Kg', '7.5 Kg', '8 Kg', '9 Kg', '10 Kg or more']),
            $this->select('load_type', 'Load Type', ['Top Load', 'Front Load']),
            $this->select('wm_type', 'Type', ['Semi-Automatic', 'Fully Automatic']),
        ];
    }

    private function refrigerator(): array
    {
        return [
            ...$this->applianceBase(
                ['Gas Filling', 'Installation / Fitting', 'General Service', 'Repair'],
                ['LG', 'Samsung', 'Whirlpool', 'Godrej', 'Haier', 'Bosch', 'Panasonic'],
                ['Not Cooling', 'Water Leak', 'Noise', 'Ice Build-up', 'Not Turning On'],
            ),
            $this->number('capacity_litres', 'Capacity (Liters)', 50, 1000),
            $this->select('door_type', 'Door Type', ['Single Door', 'Double Door', 'Side-by-Side']),
            $this->select('gas_type', 'Gas Type', ['R600a', 'R134a', 'Not sure']),
        ];
    }

    private function roPurifier(): array
    {
        return [
            ...$this->applianceBase(
                ['Installation / Fitting', 'Uninstallation', 'General Service', 'Filter Replacement', 'Repair'],
                ['Kent', 'Aquaguard', 'Pureit', 'Livpure', 'AO Smith'],
                ['No Water', 'Water Leak', 'Bad Taste / Smell', 'Low Flow', 'Noise', 'Not Switching On'],
            ),
            $this->multi('filter_replacement', 'Filter Replacement Type', ['Sediment', 'Carbon', 'Membrane']),
            $this->number('tds', 'TDS Input Level', 0, 3000),
            $this->number('tank_capacity', 'Tank Capacity (Liters)', 1, 50),
        ];
    }

    private function television(): array
    {
        return [
            ...$this->applianceBase(
                ['Installation / Wall Mounting', 'Uninstallation', 'Repair'],
                ['LG', 'Samsung', 'Sony', 'Mi', 'TCL', 'OnePlus', 'Panasonic'],
                ['No Display', 'No Sound', 'Lines on Screen', 'Not Turning On', 'Remote / Connectivity Issue'],
            ),
            $this->select('screen_size', 'Screen Size', ['32 inch', '40-43 inch', '50 inch', '55 inch', '65 inch or more']),
        ];
    }

    /** Section 3 fields shared by every solar product, in spec order. */
    /** $systemTypeRequired: the spec's table makes System Type (PV) and Tube/Plate type (water heater) mandatory. */
    private function solarBase(array $serviceTypes, array $capacities, array $systemTypes, bool $systemTypeRequired = false): array
    {
        return [
            $this->select('solar_service_type', 'Solar Service Type', $serviceTypes, true),
            $this->select('system_capacity', 'System Capacity', $capacities, true),
            $this->select('system_type', 'System Type', $systemTypes, $systemTypeRequired),
            $this->select('installation', 'Installation', ['Rooftop', 'Ground Mounted']),
        ];
    }

    private function solarPanel(): array
    {
        return [
            ...$this->solarBase(
                ['Installation', 'Panel Cleaning', 'Acid Wash', 'Breakdown', 'General Service'],
                ['1 KW', '2 KW', '3 KW', '5 KW', '7.5 KW', '10 KW', 'More than 10 KW'],
                ['On-Grid', 'Off-Grid', 'Hybrid'],
                true,
            ),
            $this->text('inverter_battery', 'Inverter & Battery Detail'),
            $this->select('structure', 'Structure / Tilt Type', ['Elevated High Structure', 'Standard']),
            $this->text('inverter_brand_serial', 'Inverter Brand / Serial'),
            $this->select('net_meter', 'Grid Net-Meter Type', ['Net Meter', 'Gross Meter', 'Not Installed']),
            $this->number('panel_count', 'Panel Count', 1, 500),
            $this->select('cleaning_frequency', 'Cleaning Frequency', ['Weekly', 'Fortnightly', 'Monthly', 'Quarterly', 'Rarely']),
            $this->text('subsidy_id', 'Government Subsidy / Scheme ID'),
        ];
    }

    private function solarWaterHeater(): array
    {
        return [
            ...$this->solarBase(
                ['Installation', 'General Service', 'Acid Wash / Descaling', 'Breakdown'],
                ['100 LPD', '150 LPD', '200 LPD', '250 LPD', '300 LPD', '500 LPD'],
                ['ETC (Glass Tube)', 'FPC (Copper Plate)'],
                true,
            ),
            $this->select('structure', 'Structure / Tilt Type', ['Elevated High Structure', 'Standard']),
            $this->select('hard_water', 'Hard Water Treatment / Scaling Issue', ['Yes', 'No', 'Not sure']),
            $this->select('tank_material', 'Tank Material', ['Stainless Steel', 'Galvanised Iron', 'Other']),
            $this->text('subsidy_id', 'Government Subsidy / Scheme ID'),
        ];
    }

    private function solarInverter(): array
    {
        return [
            ...$this->solarBase(
                ['Installation', 'Breakdown', 'General Service', 'Battery Check'],
                ['1 KVA', '2 KVA', '3 KVA', '5 KVA', '10 KVA', 'More than 10 KVA'],
                ['On-Grid', 'Off-Grid', 'Hybrid'],
            ),
            $this->text('inverter_battery', 'Inverter & Battery Detail'),
        ];
    }
}
