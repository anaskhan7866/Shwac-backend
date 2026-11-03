<?php

use App\Models\Service;
use Illuminate\Database\Migrations\Migration;

/**
 * V2 spec table: System Type is a mandatory field for the Solar Rooftop Panel (PV)
 * and the Tube/Plate type for the Solar Water Heater. The catalog seeder never
 * overwrites existing services, so the live catalog is updated here, once.
 * Only the `required` flag of that one field changes; options and other fields stay.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->setRequired(true);
    }

    public function down(): void
    {
        $this->setRequired(false);
    }

    private function setRequired(bool $required): void
    {
        foreach (Service::whereIn('code', ['SOL-PV', 'SOL-SWH'])->get() as $service) {
            $schema = $service->form_schema ?? [];
            $changed = false;
            foreach ($schema as $i => $field) {
                if (($field['key'] ?? null) === 'system_type' && (bool) ($field['required'] ?? false) !== $required) {
                    $schema[$i]['required'] = $required;
                    $changed = true;
                }
            }
            if ($changed) {
                $service->update(['form_schema' => $schema]);
            }
        }
    }
};
