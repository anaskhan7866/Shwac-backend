<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\NumberSequence;
use App\Support\FinancialYear;
use Illuminate\Support\Facades\DB;

/**
 * Gap-free, collision-free document numbering.
 *
 * Invoices restart every financial year per branch (GST rule 46: consecutive serial,
 * unique for the FY, max 16 chars). Format: {PREFIX}/{FY}/{00001} → SHW/2627/00001.
 * Other documents use a global running counter: BK-000123, CMP-000045 …
 */
class NumberGenerator
{
    private const GLOBAL_PREFIX = [
        'booking' => 'BK',
        'job' => 'JOB',
        'complaint' => 'CMP',
        'customer' => 'CUS',
    ];

    public function invoice(Branch $branch): string
    {
        $fy = FinancialYear::code(now());
        $n = $this->next('invoice', 'branch:'.$branch->id, $fy);

        return sprintf('%s/%s/%05d', strtoupper($branch->invoice_prefix), $fy, $n);
    }

    public function receipt(Branch $branch): string
    {
        $fy = FinancialYear::code(now());
        $n = $this->next('receipt', 'branch:'.$branch->id, $fy);

        return sprintf('RCT/%s/%s/%05d', strtoupper($branch->code), $fy, $n);
    }

    public function for(string $key): string
    {
        $prefix = self::GLOBAL_PREFIX[$key] ?? strtoupper($key);

        return sprintf('%s-%06d', $prefix, $this->next($key, 'global', 'all'));
    }

    /**
     * Atomically reserve the next number. Must run inside the caller's transaction
     * so a rollback releases nothing (no gaps) — the row lock serialises writers.
     */
    public function next(string $key, string $scope, string $period): int
    {
        return DB::transaction(function () use ($key, $scope, $period) {
            $seq = NumberSequence::query()
                ->where(compact('key', 'scope', 'period'))
                ->lockForUpdate()
                ->first();

            if (! $seq) {
                $seq = NumberSequence::create(compact('key', 'scope', 'period') + ['next_number' => 1]);
            }

            $n = $seq->next_number;
            $seq->increment('next_number');

            return $n;
        });
    }
}
