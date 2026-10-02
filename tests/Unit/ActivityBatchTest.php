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

    public function test_a_lifecycle_entry_takes_the_root_over_from_an_earlier_secondary_one_of_the_same_subject(): void
    {
        $batch = new ActivityBatch;
        $batch->start();

        $this->assertTrue($batch->claimRoot('A:1', false), 'the pivot comes first');
        $this->assertNull($batch->confirmRoot(10));

        $this->assertTrue($batch->claimRoot('A:1', true), 'the update takes over');
        $this->assertSame(10, $batch->confirmRoot(11), 'the pivot row is to be demoted');

        $this->assertFalse($batch->claimRoot('A:1', true));
        $this->assertFalse($batch->claimRoot('A:1', false));
    }

    public function test_a_lifecycle_entry_first_stays_the_root(): void
    {
        $batch = new ActivityBatch;
        $batch->start();

        $this->assertTrue($batch->claimRoot('A:1', true));
        $batch->confirmRoot(10);

        $this->assertFalse($batch->claimRoot('A:1', false));
        $this->assertFalse($batch->claimRoot('A:1', true));
    }

    public function test_another_subject_never_takes_the_root(): void
    {
        $batch = new ActivityBatch;
        $batch->start();

        $this->assertTrue($batch->claimRoot('A:1', false));
        $batch->confirmRoot(10);

        $this->assertFalse($batch->claimRoot('B:2', true));
    }

    public function test_run_restores_the_root_tracking_too(): void
    {
        $batch = new ActivityBatch;
        $batch->start();
        $batch->claimRoot('A:1', false);
        $batch->confirmRoot(10);

        $batch->run(function () use ($batch): void {
            $this->assertTrue($batch->claimRoot('B:2', true));
            $batch->confirmRoot(20);
        });

        $this->assertTrue($batch->claimRoot('A:1', true), 'the outer secondary root is still replaceable');
        $this->assertSame(10, $batch->confirmRoot(12));
    }
}
