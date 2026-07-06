<?php
/**
 * Pure-unit test for SignatureValidator HMAC contract.
 * Locks the wire format `HMAC-SHA256(secret, "{timestamp}.{body}")` against
 * the corresponding implementation in `_shared/hmac.ts` (Supabase side).
 * Any change here is breaking and MUST land on both sides simultaneously.
 */

namespace Hubbee\Tests\Unit\SaaS;

use Hubbee\SaaS\SignatureValidator;
use PHPUnit\Framework\TestCase;

class SignatureValidatorTest extends TestCase {

    private const SECRET    = 'super-secret-key-do-not-use-in-prod';
    private const TIMESTAMP = '1714560000';
    private const BODY      = '{"event_type":"heartbeat","data":{"alive":true}}';

    public function test_compute_hmac_returns_lowercase_hex_sha256(): void {
        $sig = SignatureValidator::compute_hmac( self::BODY, self::TIMESTAMP, self::SECRET );

        self::assertSame( 64, strlen( $sig ), 'sha256 hex is 64 chars' );
        self::assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $sig );
    }

    public function test_compute_hmac_is_deterministic(): void {
        $a = SignatureValidator::compute_hmac( self::BODY, self::TIMESTAMP, self::SECRET );
        $b = SignatureValidator::compute_hmac( self::BODY, self::TIMESTAMP, self::SECRET );

        self::assertSame( $a, $b );
    }

    public function test_compute_hmac_differs_on_body_change(): void {
        $a = SignatureValidator::compute_hmac( self::BODY, self::TIMESTAMP, self::SECRET );
        $b = SignatureValidator::compute_hmac( self::BODY . ' ', self::TIMESTAMP, self::SECRET );

        self::assertNotSame( $a, $b, 'one trailing space must invalidate the signature' );
    }

    public function test_compute_hmac_differs_on_timestamp_change(): void {
        $a = SignatureValidator::compute_hmac( self::BODY, self::TIMESTAMP, self::SECRET );
        $b = SignatureValidator::compute_hmac( self::BODY, (int) self::TIMESTAMP + 1, self::SECRET );

        self::assertNotSame( $a, $b );
    }

    public function test_compute_hmac_differs_on_secret_change(): void {
        $a = SignatureValidator::compute_hmac( self::BODY, self::TIMESTAMP, self::SECRET );
        $b = SignatureValidator::compute_hmac( self::BODY, self::TIMESTAMP, self::SECRET . 'x' );

        self::assertNotSame( $a, $b );
    }

    /**
     * Wire-format lock: this exact (body, timestamp, secret) → signature
     * mapping is the contract Supabase + WordPress both implement. If this
     * test fails, the format drifted and pushes will start 401-ing.
     */
    public function test_compute_hmac_matches_known_vector(): void {
        $expected = hash_hmac(
            'sha256',
            self::TIMESTAMP . '.' . self::BODY,
            self::SECRET
        );

        $actual = SignatureValidator::compute_hmac( self::BODY, self::TIMESTAMP, self::SECRET );

        self::assertSame( $expected, $actual );
    }

    public function test_compute_hmac_accepts_int_timestamp(): void {
        $string_ts = SignatureValidator::compute_hmac( self::BODY, self::TIMESTAMP, self::SECRET );
        $int_ts    = SignatureValidator::compute_hmac( self::BODY, (int) self::TIMESTAMP, self::SECRET );

        self::assertSame( $string_ts, $int_ts );
    }

    public function test_compute_hmac_handles_empty_body(): void {
        $sig = SignatureValidator::compute_hmac( '', self::TIMESTAMP, self::SECRET );

        self::assertSame( 64, strlen( $sig ), 'empty body still produces a valid hmac' );
    }

    public function test_is_timestamp_fresh_accepts_now(): void {
        $now = 1714560000;
        self::assertTrue( SignatureValidator::is_timestamp_fresh( $now, $now ) );
    }

    public function test_is_timestamp_fresh_accepts_within_tolerance(): void {
        $now = 1714560000;
        // Tolerance is 60 s; ±59 s must pass.
        self::assertTrue( SignatureValidator::is_timestamp_fresh( $now - 59, $now ) );
        self::assertTrue( SignatureValidator::is_timestamp_fresh( $now + 59, $now ) );
    }

    public function test_is_timestamp_fresh_rejects_stale(): void {
        $now = 1714560000;
        // 61 s old must fail (replay window).
        self::assertFalse( SignatureValidator::is_timestamp_fresh( $now - 61, $now ) );
    }

    public function test_is_timestamp_fresh_rejects_far_future(): void {
        $now = 1714560000;
        // Symmetric window — far-future timestamps are also rejected
        // (otherwise an attacker could pre-compute signatures).
        self::assertFalse( SignatureValidator::is_timestamp_fresh( $now + 61, $now ) );
    }
}
