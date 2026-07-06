<?php
/**
 * Unit tests for the M2M endpoint SSRF allowlist (ModeConfig::is_allowed_m2m_host).
 *
 * @package Hubbee\Tests
 */

namespace Hubbee\Tests\Unit\SaaS;

use Hubbee\SaaS\ModeConfig;
use PHPUnit\Framework\TestCase;

class ModeConfigTest extends TestCase {

    /**
     * Force PRODUCTION mode by default: non-localhost home URL, no bz_mode option.
     */
    protected function setUp(): void {
        $GLOBALS['__wp_home_url'] = 'https://example.com';
        $GLOBALS['__wp_options']  = [ 'bz_mode' => '' ];
        ModeConfig::get_instance()->clear_cache();
    }

    public function test_allows_hubbee_and_supabase_https_hosts(): void {
        $config = ModeConfig::get_instance();
        $this->assertTrue( $config->is_allowed_m2m_host( 'https://api.hubbee.io' ) );
        $this->assertTrue( $config->is_allowed_m2m_host( 'https://lkmqnvkyneonwrjmpqoz.supabase.co' ) );
        $this->assertTrue( $config->is_allowed_m2m_host( 'https://foo.hubbee.io/functions/v1' ) );
    }

    public function test_rejects_non_https_in_production(): void {
        $config = ModeConfig::get_instance();
        $this->assertFalse( $config->is_allowed_m2m_host( 'http://api.hubbee.io' ) );
    }

    public function test_rejects_arbitrary_internal_and_spoofed_hosts(): void {
        $config = ModeConfig::get_instance();
        $this->assertFalse( $config->is_allowed_m2m_host( 'https://evil.example.com' ), 'arbitrary host' );
        $this->assertFalse( $config->is_allowed_m2m_host( 'https://localhost' ), 'localhost target' );
        $this->assertFalse( $config->is_allowed_m2m_host( 'https://10.0.0.1' ), 'private IP' );
        $this->assertFalse( $config->is_allowed_m2m_host( 'https://169.254.169.254' ), 'cloud metadata IP' );
        $this->assertFalse( $config->is_allowed_m2m_host( 'https://hubbee.io.evil.com' ), 'suffix spoof' );
        $this->assertFalse( $config->is_allowed_m2m_host( 'not-a-url' ), 'unparseable' );
        $this->assertFalse( $config->is_allowed_m2m_host( '' ), 'empty' );
    }

    public function test_dev_mode_relaxes_to_any_http_or_https(): void {
        // Localhost site → dev mode → may target a local API server.
        $GLOBALS['__wp_home_url'] = 'http://localhost';
        ModeConfig::get_instance()->clear_cache();

        $config = ModeConfig::get_instance();
        $this->assertTrue( $config->is_allowed_m2m_host( 'http://localhost:3100' ) );
        $this->assertTrue( $config->is_allowed_m2m_host( 'https://api.hubbee.io' ) );
        $this->assertFalse( $config->is_allowed_m2m_host( 'ftp://localhost' ), 'non-http scheme still rejected' );
    }
}
