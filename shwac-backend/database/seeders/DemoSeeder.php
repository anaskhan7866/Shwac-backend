<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryService;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Demo data for local development. Logins (password: Password@123):
 *   admin@shwac.test (Super Admin) · manager@shwac.test · accounts@shwac.test · operator@shwac.test
 *   tech1@shwac.test / tech2@shwac.test (technicians)
 * Customer app: OTP login with 9876543210 (OTP is returned in the API response in local env).
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $hq = Branch::firstOrCreate(['code' => 'HQ'], [
            'name' => 'Head Office', 'state' => 'Uttar Pradesh', 'state_code' => '09', 'invoice_prefix' => 'SHW',
            'city' => 'Meerut', 'pincode' => '250001', 'address' => 'Main Road', 'phone' => '9000000000',
        ]);
        Branch::firstOrCreate(['code' => 'DEL'], [
            'name' => 'Delhi Branch', 'state' => 'Delhi', 'state_code' => '07', 'invoice_prefix' => 'SHD',
            'city' => 'New Delhi', 'pincode' => '110001',
        ]);

        $users = [
            ['Super Admin', 'admin@shwac.test', '9000000001', 'staff', 'super_admin', null],
            ['Branch Manager', 'manager@shwac.test', '9000000002', 'staff', 'branch_manager', $hq->id],
            ['Accountant', 'accounts@shwac.test', '9000000003', 'staff', 'accountant', $hq->id],
            ['Operator', 'operator@shwac.test', '9000000004', 'staff', 'operator', $hq->id],
            ['Ravi Kumar', 'tech1@shwac.test', '9000000005', 'technician', 'technician', $hq->id],
            ['Amit Singh', 'tech2@shwac.test', '9000000006', 'technician', 'technician', $hq->id],
        ];
        foreach ($users as [$name, $email, $phone, $type, $role, $branch]) {
            User::firstOrCreate(['email' => $email], [
                'name' => $name, 'phone' => $phone, 'user_type' => $type, 'branch_id' => $branch,
                'password' => 'Password@123',
            ])->assignRole($role);
        }

        $ro = ServiceCategory::firstOrCreate(['slug' => 'ro-purifier'], ['name' => 'RO Water Purifier', 'sort_order' => 1]);
        $solar = ServiceCategory::firstOrCreate(['slug' => 'solar'], ['name' => 'Solar Water Heater', 'sort_order' => 2]);
        $appl = ServiceCategory::firstOrCreate(['slug' => 'appliances'], ['name' => 'Home Appliances', 'sort_order' => 3]);

        $brandField = ['key' => 'brand', 'label' => 'Brand', 'type' => 'select', 'required' => true,
            'options' => ['Kent', 'Aquaguard', 'Pureit', 'Livpure', 'AO Smith', 'Other']];

        $services = [
            [$ro, 'RO-INST', 'RO Installation', 499, [$brandField,
                ['key' => 'wall_type', 'label' => 'Wall type', 'type' => 'select', 'options' => ['Brick', 'Tile', 'Wood'], 'required' => true],
                ['key' => 'water_source', 'label' => 'Water source', 'type' => 'select', 'options' => ['Municipal', 'Borewell', 'Tanker'], 'required' => true],
                ['key' => 'job_type', 'label' => 'Job type', 'type' => 'select', 'options' => ['installation'], 'required' => false],
            ]],
            [$ro, 'RO-SRV', 'RO General Service', 399, [$brandField,
                ['key' => 'last_service', 'label' => 'Last serviced on', 'type' => 'date'],
            ]],
            [$ro, 'RO-FLT', 'Filter & Membrane Change', 1499, [$brandField,
                ['key' => 'tds', 'label' => 'Current TDS (if known)', 'type' => 'number', 'min' => 0, 'max' => 3000],
            ]],
            [$ro, 'RO-REP', 'RO Repair / Not Working', 349, [$brandField,
                ['key' => 'issue', 'label' => 'What is the problem?', 'type' => 'multiselect', 'options' => ['No water', 'Leakage', 'Bad taste', 'Noise', 'Not switching on'], 'required' => true],
            ]],
            [$solar, 'SOL-SRV', 'Solar Heater Service', 799, [
                ['key' => 'capacity_lpd', 'label' => 'Capacity (litres/day)', 'type' => 'select', 'options' => ['100', '150', '200', '300', '500']],
            ]],
            [$appl, 'APL-INSP', 'Appliance Inspection Visit', 299, []],
        ];
        foreach ($services as $i => [$cat, $code, $name, $price, $schema]) {
            Service::firstOrCreate(['code' => $code], [
                'service_category_id' => $cat->id, 'name' => $name, 'base_price' => $price, 'tax_rate' => 18,
                'sac_code' => '998719', 'form_schema' => $schema, 'sort_order' => $i,
            ]);
        }

        // ---- Inventory: main store, spare parts with barcodes, stock issued to each technician's van
        $inv = app(InventoryService::class);
        $store = Warehouse::firstOrCreate(['code' => 'HQ-MAIN'], ['branch_id' => $hq->id, 'name' => 'HQ Main Store', 'type' => 'main']);
        $parts = [
            ['SED-10', 'Sediment filter 10"', 'filter', '84212190', 250, 120, '8901030865071', 20, 3],
            ['CRB-10', 'Carbon filter (post)', 'filter', '84212190', 450, 260, '8901030865088', 15, 2],
            ['MEM-80', 'RO membrane 80 GPD', 'membrane', '84212190', 1800, 1150, '8901030865095', 8, 1],
            ['PIPE-FG', 'Food-grade pipe (per m)', 'consumable', '39173990', 40, 18, '8901030865101', 50, 10],
            ['SV-24', 'Inlet solenoid valve 24V', 'valve', '84818090', 380, 210, '8901030865118', 10, 1],
            ['PMP-75', 'RO booster pump 75 GPD', 'pump', '84137010', 2200, 1450, '8901030865125', 4, 0],
        ];
        $staffUser = User::where('email', 'manager@shwac.test')->first();
        foreach ($parts as [$sku, $name, $cat, $hsn, $price, $cost, $barcode, $reorder, $vanMin]) {
            $p = Product::firstOrCreate(['sku' => $sku], [
                'name' => $name, 'category' => $cat, 'hsn' => $hsn, 'sale_price' => $price, 'purchase_price' => $cost,
                'tax_rate' => 18, 'barcode' => $barcode, 'reorder_level' => $reorder, 'van_min_level' => $vanMin, 'warranty_months' => 6,
            ]);
            if ($p->wasRecentlyCreated) {
                $inv->receive($store, $p, $reorder * 3, $staffUser, $cost, 'Opening stock');
            }
        }
        foreach (User::where('user_type', 'technician')->get() as $tech) {
            $tech->update(['location_consent_at' => $tech->location_consent_at ?? now()]);
            $van = $inv->vanFor($tech);
            if ($van->wasRecentlyCreated) {
                foreach (Product::whereIn('sku', ['SED-10', 'CRB-10', 'MEM-80', 'PIPE-FG', 'SV-24'])->get() as $p) {
                    $inv->transfer($store, $van, $p, $p->sku === 'PIPE-FG' ? 20 : ($p->sku === 'MEM-80' ? 2 : 5), $staffUser, 'Opening van stock');
                }
            }
        }

        if (! Customer::where('phone', '9876543210')->exists()) {
            $c = Customer::create([
                'code' => 'CUS-DEMO01', 'branch_id' => $hq->id, 'name' => 'Sunita Sharma',
                'phone' => '9876543210', 'whatsapp' => '9876543210', 'email' => 'sunita@example.com',
            ]);
            $addr = $c->addresses()->create([
                'label' => 'Home', 'line1' => '12, Shastri Nagar', 'city' => 'Meerut', 'state' => 'Uttar Pradesh',
                'state_code' => '09', 'pincode' => '250004', 'lat' => 28.9845, 'lng' => 77.7064, 'is_default' => true,
            ]);
            $c->products()->create([
                'customer_address_id' => $addr->id, 'category' => 'RO purifier', 'product_name' => 'Kent Grand Plus',
                'brand' => 'Kent', 'model' => 'Grand Plus', 'serial_no' => 'KGP-2025-0042',
                'purchase_date' => now()->subMonths(8), 'warranty_until' => now()->addMonths(4),
            ]);
        }
    }
}
