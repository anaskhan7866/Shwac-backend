<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\ServiceJob;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ComplaintSlaTest extends TestCase
{
    #[Test]
    public function complaint_is_auto_assigned_to_least_loaded_technician_with_sla_timers(): void
    {
        $this->fakeExternal();
        $busy = $this->technician();
        $free = $this->technician();
        $this->technician($this->makeBranch('DEL', '07', 'Delhi')); // other branch, never picked

        // give $busy an active job
        $c0 = $this->customer();
        ServiceJob::create(['number' => 'JOB-X1', 'branch_id' => $this->branch->id, 'customer_id' => $c0->id,
            'customer_address_id' => $c0->addresses()->first()->id, 'technician_id' => $busy->id, 'status' => 'in_progress']);

        $customer = $this->customer();
        $this->actingAsUser($this->customerUser($customer));
        $res = $this->postJson('/api/v1/customer/complaints', ['subject' => 'No water from RO', 'category' => 'no_water', 'priority' => 'critical'])
            ->assertCreated()->assertJsonPath('status', 'assigned')->assertJsonPath('priority', 'normal'); // customers can't set priority

        $complaint = Complaint::findOrFail($res->json('id'));
        $this->assertSame($free->id, $complaint->assigned_to);
        $this->assertEquals(now()->addMinutes(1440), $complaint->sla_resolution_due_at);
        $this->assertNotNull($complaint->first_response_at);
        $this->assertSame(1, $complaint->jobs()->count());
        $this->assertSame('assigned', $complaint->jobs()->first()->status);
        $this->assertDatabaseHas('notification_logs', ['event' => 'complaint_registered', 'status' => 'ready']);

        // Customer tracking view never leaks internal notes
        $this->actingAsUser($this->staff('operator'));
        $this->postJson("/api/v1/admin/complaints/{$complaint->id}/comments", ['body' => 'Customer is rude', 'is_internal' => true])->assertCreated();
        $this->postJson("/api/v1/admin/complaints/{$complaint->id}/comments", ['body' => 'Technician will reach by 2pm'])->assertCreated();
        $this->actingAsUser($customer->user);
        $bodies = collect($this->getJson("/api/v1/customer/complaints/{$complaint->id}")->assertOk()->json('updates'))->pluck('body');
        $this->assertTrue($bodies->contains('Technician will reach by 2pm'));
        $this->assertFalse($bodies->contains('Customer is rude'));
    }

    #[Test]
    public function sla_breaches_are_flagged_by_the_scheduler_command(): void
    {
        $this->fakeExternal();
        $customer = $this->customer();
        $this->actingAsUser($this->staff('operator'));

        // No technicians → stays open, nobody has responded
        $id = $this->postJson('/api/v1/admin/complaints', [
            'customer_id' => $customer->id, 'subject' => 'Leakage', 'priority' => 'critical',
        ])->assertCreated()->assertJsonPath('status', 'open')->json('id');

        $this->travel(20)->minutes();
        $this->artisan('complaints:check-sla')->expectsOutputToContain('Flagged 0')->assertSuccessful();

        $this->travel(15)->minutes(); // > 30 min response SLA
        $this->artisan('complaints:check-sla')->expectsOutputToContain('Flagged 1')->assertSuccessful();
        $this->artisan('complaints:check-sla')->expectsOutputToContain('Flagged 0'); // only once

        $this->assertTrue(Complaint::find($id)->sla_breached);
        $this->getJson('/api/v1/admin/complaints?breached=1')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/v1/admin/dashboard')->assertOk()->assertJsonPath('complaints.sla_breached', 1);
    }

    #[Test]
    public function completing_the_repair_job_resolves_the_complaint_and_it_can_be_reopened(): void
    {
        $this->fakeExternal();
        $tech = $this->technician();
        $customer = $this->customer();
        $this->actingAsUser($this->staff('operator'));
        $id = $this->postJson('/api/v1/admin/complaints', ['customer_id' => $customer->id, 'subject' => 'Bad taste'])->assertCreated()->json('id');
        $job = Complaint::find($id)->jobs()->firstOrFail();

        $this->actingAsUser($tech);
        foreach (['accepted', 'en_route', 'in_progress'] as $s) {
            $this->postJson("/api/v1/technician/jobs/{$job->id}/status", ['status' => $s])->assertOk();
        }
        $this->assertSame('in_progress', Complaint::find($id)->status);
        $this->postJson("/api/v1/technician/jobs/{$job->id}/status", ['status' => 'completed'])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->postJson("/api/v1/technician/jobs/{$job->id}/status", ['status' => 'completed', 'note' => 'Replaced carbon filter'])->assertOk();

        $complaint = Complaint::find($id);
        $this->assertSame('resolved', $complaint->status);
        $this->assertNotNull($complaint->resolved_at);

        $this->actingAsUser($this->customerUser($customer));
        $this->postJson("/api/v1/customer/complaints/{$id}/comments", ['body' => 'Taste is still bad', 'reopen' => true])
            ->assertCreated()->assertJsonPath('status', 'reopened');
    }

    #[Test]
    public function technicians_cannot_touch_other_technicians_jobs(): void
    {
        $this->fakeExternal();
        [$a, $b] = [$this->technician(), $this->technician()];
        $customer = $this->customer();
        $this->actingAsUser($this->staff('operator'));
        $id = $this->postJson('/api/v1/admin/complaints', ['customer_id' => $customer->id, 'subject' => 'Noise', 'auto_assign' => false])->json('id');
        $this->postJson("/api/v1/admin/complaints/{$id}/assign", ['technician_id' => $a->id])->assertOk();
        $job = Complaint::find($id)->jobs()->first();

        $this->actingAsUser($b);
        $this->getJson("/api/v1/technician/jobs/{$job->id}")->assertNotFound();
        $this->postJson("/api/v1/technician/jobs/{$job->id}/status", ['status' => 'accepted'])->assertNotFound();
    }
}
