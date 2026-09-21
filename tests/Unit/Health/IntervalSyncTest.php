<?php
/**
 * Unit tests for plan-driven interval sync (IntervalSync + scheduler guards).
 *
 * The load-bearing semantics: an explicit 0 from the SaaS unschedules the
 * heartbeat / health snapshot (free plan); absent keys are a no-op (old
 * servers); positive values reschedule only when changed.
 *
 * @package Hubbee\Tests
 */

namespace Hubbee\Tests\Unit\Health;

use Hubbee\Health\HeartbeatScheduler;
use Hubbee\Health\HealthScheduler;
use Hubbee\Health\IntervalSync;
use PHPUnit\Framework\TestCase;

class IntervalSyncTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['__wp_options'] = [];
        $GLOBALS['__wp_cron']    = [];
    }

    private function schedule_both(): void {
        $GLOBALS['__wp_cron'][ HeartbeatScheduler::HOOK ]      = 1000;
        $GLOBALS['__wp_cron'][ HealthScheduler::HOOK_SNAPSHOT ] = 1000;
        $GLOBALS['__wp_cron'][ HealthScheduler::HOOK_DEEP ]     = 1000;
    }

    public function test_absent_keys_are_a_noop(): void {
        $this->schedule_both();
        IntervalSync::apply( [ 'acknowledged' => true ] );

        $this->assertArrayNotHasKey( 'bz_heartbeat_interval', $GLOBALS['__wp_options'] );
        $this->assertArrayNotHasKey( 'bz_health_check_interval', $GLOBALS['__wp_options'] );
        $this->assertNotFalse( wp_next_scheduled( HeartbeatScheduler::HOOK ) );
        $this->assertNotFalse( wp_next_scheduled( HealthScheduler::HOOK_SNAPSHOT ) );
    }

    public function test_explicit_zero_unschedules_heartbeat_and_snapshot_but_not_deep(): void {
        $this->schedule_both();
        IntervalSync::apply( [
            'heartbeat_interval_seconds'    => 0,
            'health_check_interval_minutes' => 0,
        ] );

        $this->assertSame( 0, $GLOBALS['__wp_options']['bz_heartbeat_interval'] );
        $this->assertSame( 0, $GLOBALS['__wp_options']['bz_health_check_interval'] );
        $this->assertFalse( wp_next_scheduled( HeartbeatScheduler::HOOK ) );
        $this->assertFalse( wp_next_scheduled( HealthScheduler::HOOK_SNAPSHOT ) );
        // The daily deep check survives on every plan (inventory freshness).
        $this->assertNotFalse( wp_next_scheduled( HealthScheduler::HOOK_DEEP ) );
        $this->assertTrue( HeartbeatScheduler::is_disabled() );
        $this->assertTrue( HealthScheduler::is_snapshot_disabled() );
    }

    public function test_zero_twice_is_idempotent(): void {
        $this->schedule_both();
        IntervalSync::apply( [ 'heartbeat_interval_seconds' => 0 ] );
        // Second delivery: already stored 0 and no cron — nothing to do.
        IntervalSync::apply( [ 'heartbeat_interval_seconds' => 0 ] );

        $this->assertSame( 0, $GLOBALS['__wp_options']['bz_heartbeat_interval'] );
        $this->assertFalse( wp_next_scheduled( HeartbeatScheduler::HOOK ) );
    }

    public function test_positive_changed_value_stores_and_reschedules(): void {
        $this->schedule_both();
        IntervalSync::apply( [
            'heartbeat_interval_seconds'    => 180,
            'health_check_interval_minutes' => 10,
        ] );

        $this->assertSame( 180, $GLOBALS['__wp_options']['bz_heartbeat_interval'] );
        $this->assertSame( 600, $GLOBALS['__wp_options']['bz_health_check_interval'] );
        $this->assertNotFalse( wp_next_scheduled( HeartbeatScheduler::HOOK ) );
        $this->assertNotFalse( wp_next_scheduled( HealthScheduler::HOOK_SNAPSHOT ) );
    }

    public function test_value_equal_to_default_does_not_write_when_unset(): void {
        $this->schedule_both();
        IntervalSync::apply( [ 'heartbeat_interval_seconds' => HeartbeatScheduler::DEFAULT_INTERVAL ] );

        // Option was never synced and the value matches the default — no write,
        // no redundant reschedule.
        $this->assertArrayNotHasKey( 'bz_heartbeat_interval', $GLOBALS['__wp_options'] );
        $this->assertSame( 1000, wp_next_scheduled( HeartbeatScheduler::HOOK ) );
    }

    public function test_reactivation_after_zero_reschedules(): void {
        IntervalSync::apply( [ 'heartbeat_interval_seconds' => 0 ] );
        $this->assertTrue( HeartbeatScheduler::is_disabled() );

        // Upgrade: the reconcile poke (manual poll) delivers a positive value.
        IntervalSync::apply( [ 'heartbeat_interval_seconds' => 300 ] );

        $this->assertSame( 300, $GLOBALS['__wp_options']['bz_heartbeat_interval'] );
        $this->assertFalse( HeartbeatScheduler::is_disabled() );
        $this->assertNotFalse( wp_next_scheduled( HeartbeatScheduler::HOOK ) );
    }

    public function test_scheduler_reschedule_respects_stored_zero(): void {
        $GLOBALS['__wp_options']['bz_heartbeat_interval']    = 0;
        $GLOBALS['__wp_options']['bz_health_check_interval'] = 0;
        $this->schedule_both();

        HeartbeatScheduler::reschedule();
        HealthScheduler::reschedule();

        $this->assertFalse( wp_next_scheduled( HeartbeatScheduler::HOOK ) );
        $this->assertFalse( wp_next_scheduled( HealthScheduler::HOOK_SNAPSHOT ) );
    }

    public function test_activation_schedule_respects_stored_zero(): void {
        $GLOBALS['__wp_options']['bz_heartbeat_interval'] = 0;

        HeartbeatScheduler::schedule();

        $this->assertFalse( wp_next_scheduled( HeartbeatScheduler::HOOK ) );
    }

    public function test_is_disabled_is_false_without_synced_option(): void {
        $this->assertFalse( HeartbeatScheduler::is_disabled() );
        $this->assertFalse( HealthScheduler::is_snapshot_disabled() );
    }
}
