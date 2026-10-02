<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus\Tests\Unit;

use Asignua\FilamentActivityLogPlus\ActivityBatch;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ActivityBatchTest extends TestCase
{
    public function test_outside_a_batch_every_entry_is_a_root(): void
    {
        $batch = new ActivityBatch;

        $this->assertNull($batch->uuid());
        $this->assertTrue($batch->claimRoot());
        $this->assertTrue($batch->claimRoot());
    }

    public function test_inside_a_batch_only_the_first_entry_is_the_root(): void
    {
        $batch = new ActivityBatch;
        $uuid = $batch->start();

        $this->assertSame($uuid, $batch->uuid());
        $this->assertTrue($batch->claimRoot());
        $this->assertFalse($batch->claimRoot());

        $batch->end();

        $this->assertNull($batch->uuid());
    }

    public function test_run_restores_the_previous_state_so_batches_nest(): void
    {
        $batch = new ActivityBatch;
        $outer = $batch->start();
        $batch->claimRoot();

        $result = $batch->run(function () use ($batch, $outer): string {
            $this->assertNotSame($outer, $batch->uuid());
            $this->assertTrue($batch->claimRoot());

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertSame($outer, $batch->uuid());
        $this->assertFalse($batch->claimRoot(), 'the outer root was already claimed');
    }

    public function test_run_restores_the_state_when_the_callback_throws(): void
    {
        $batch = new ActivityBatch;

        try {
            $batch->run(function (): void {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
        }

        $this->assertNull($batch->uuid());
    }
}
