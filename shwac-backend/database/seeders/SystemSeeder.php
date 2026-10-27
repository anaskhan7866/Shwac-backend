<?php

namespace Database\Seeders;

use App\Models\NotificationTemplate;
use App\Models\Setting;
use App\Models\SlaPolicy;
use Illuminate\Database\Seeder;

/**
 * Default configuration: company profile, SLA policies, WhatsApp templates.
 * Template names must match the templates you submit for approval in Meta Business Manager.
 */
class SystemSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            'company' => [
                'name' => 'Shree Hargangeshwar Water Care',
                'legal_name' => 'Shree Hargangeshwar Water Care',
                'gstin' => null, 'pan' => null,
                'phone' => null, 'email' => null, 'website' => null,
            ],
            'branding' => [
                'primary_color' => '#0b6e99',
                'logo_url' => null,
                'invoice_terms' => "Payment due within 7 days.\nWarranty as per manufacturer terms.\nSubject to local jurisdiction.",
            ],
            'tax' => ['default_rate' => 18, 'default_sac' => '998719', 'prices_include_tax' => false],
            'complaints' => [
                'auto_assign' => true,
                'categories' => ['no_water', 'leakage', 'taste_odour', 'low_flow', 'noise', 'electrical', 'filter_change', 'general'],
            ],
            'booking' => [
                'time_slots' => ['09:00-11:00', '11:00-13:00', '14:00-16:00', '16:00-18:00'],
                'lead_days' => 0,
            ],
        ];
        foreach ($defaults as $key => $value) {
            if (Setting::where('key', $key)->doesntExist()) {
                Setting::put($key, $value);
            }
        }

        foreach ([
            ['critical', 30, 240], ['high', 60, 480], ['normal', 120, 1440], ['low', 240, 2880],
        ] as [$priority, $respond, $resolve]) {
            SlaPolicy::firstOrCreate(['priority' => $priority], ['response_minutes' => $respond, 'resolution_minutes' => $resolve]);
        }

        // Message texts for the free click-to-send WhatsApp mode. Edit them from the admin
        // (Settings → Message templates). {placeholders} are filled in automatically.
        // template name + variables are only used if you later switch to the paid Cloud API.
        $templates = [
            ['booking_confirmed', 'shwac_booking_confirmed', ['customer_name', 'booking_number', 'service_name', 'scheduled_date', 'time_slot'],
                "Namaste {customer_name} 🙏\nYour booking *{booking_number}* for *{service_name}* is confirmed for *{scheduled_date}* ({time_slot}).\nWe will share the technician's details soon.\n– {company_name}"],
            ['technician_assigned', 'shwac_technician_assigned', ['customer_name', 'technician_name', 'technician_phone', 'scheduled_date', 'job_number'],
                "Namaste {customer_name}, *{technician_name}* ({technician_phone}) will visit you on *{scheduled_date}* ({time_slot}) for job {job_number}.\n– {company_name}"],
            ['technician_on_the_way', 'shwac_on_the_way', ['customer_name', 'technician_name', 'technician_phone'],
                "Namaste {customer_name}, our technician *{technician_name}* is on the way to you. Call {technician_phone} if needed.\n– {company_name}"],
            ['complaint_registered', 'shwac_complaint_registered', ['customer_name', 'complaint_number', 'subject', 'expected_by'],
                "Namaste {customer_name}, your complaint *{complaint_number}* ({subject}) is registered. We aim to resolve it by *{expected_by}*.\n– {company_name}"],
            ['invoice_issued', 'shwac_invoice_issued', ['customer_name', 'invoice_number', 'amount', 'due_date'],
                "Namaste {customer_name}, thank you for choosing {company_name}.\nInvoice *{invoice_number}* for *{amount}* (due {due_date}).\nView the GST invoice and pay by UPI here:\n{link}"],
            ['payment_received', 'shwac_payment_received', ['customer_name', 'amount', 'invoice_number', 'receipt_number'],
                "Thank you {customer_name}! We received *{amount}* against invoice {invoice_number}. Receipt no. {receipt_number}.\n{link}\n– {company_name}"],
            ['payment_reminder', 'shwac_payment_reminder', ['customer_name', 'invoice_number', 'amount', 'due_date'],
                "Namaste {customer_name}, a gentle reminder: *{amount}* is due on invoice {invoice_number} (due {due_date}). Pay by UPI here:\n{link}\n– {company_name}"],
        ];
        foreach ($templates as [$event, $name, $vars, $body]) {
            $t = NotificationTemplate::firstOrCreate(
                ['event' => $event, 'channel' => 'whatsapp'],
                ['provider_template' => $name, 'language' => 'en', 'variables' => $vars, 'preview' => $body, 'body' => $body, 'is_active' => true],
            );
            if ($t->body === null) {
                $t->update(['body' => $body]);
            }
        }
        // WhatsApp OTP is replaced by SMS OTP
        NotificationTemplate::where('event', 'otp_login')->where('channel', 'whatsapp')->update(['is_active' => false]);
    }
}
