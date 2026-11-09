<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SingleBranchMergeTest extends TestCase
{
    #[Test]
    public function every_record_moves_into_the_main_branch_and_other_branches_are_switched_off(): void
    {
        $delhi = $this->makeBranch('DEL', '07', 'Delhi');
        $tech = $this->technician($delhi);
        $customer = $this->customer($delhi);
        $admin = User::factory()->withRole('super_admin')->create(['branch_id' => null]);

        $migration = require database_path('migrations/2026_11_09_100000_merge_into_single_branch.php');
        $migration->up();

        $this->assertSame($this->branch->id, (int) $tech->fresh()->branch_id);
        $this->assertSame($this->branch->id, (int) $customer->fresh()->branch_id);
        $this->assertNull($admin->fresh()->branch_id); // Super Admin stays without a branch
        $this->assertFalse($delhi->fresh()->is_active);
        $this->assertTrue($this->branch->fresh()->is_active);
        $this->assertSame(1, Branch::where('is_active', true)->count());

        $migration->up(); // safe to run again
        $this->assertSame($this->branch->id, (int) $tech->fresh()->branch_id);
    }
}
