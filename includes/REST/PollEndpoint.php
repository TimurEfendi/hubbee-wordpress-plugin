<?php
/**
 * Poll Endpoint - Trigger command polling from SaaS
 *
 * This endpoint allows external schedulers (launchd, systemd, cron) to
 * trigger command polling. This is the PRIMARY method for WordPress to
 * receive commands from SaaS in DEV mode (localhost can't be reached).
 *
 * Workflow:
 * 1. External scheduler calls POST /bz/v1/poll (with Bearer token)
 * 2. WordPress polls /site-commands Edge Function
 * 3. Commands are executed via CommandExecutor
 * 4. Results are reported back to SaaS
 *
 * @package Hubbee\REST
 */

namespace Hubbee\REST;

use WP_REST_Request;
use WP_REST_Response;
use Hubbee\Agent\CommandPoller;
use Hubbee\Security\Authenticator;

class PollEndpoint extends RestEndpoint {

    protected function get_route(): string {
        return '/poll';
    }

    protected function get_methods(): string {
        return 'POST';
    }

    /**
     * Bearer-token (api_secret) auth — different from the SaaS-side HMAC
     * since this is called by external schedulers (launchd / systemd / cron),
     * not by SaaS itself.
     */
    protected function get_permission_callback(): callable {
        return [ Authenticator::class, 'authenticate_bearer' ];
    }

    public function handle_request( WP_REST_Request $request ) {
        $poller = CommandPoller::get_instance();
        $result = $poller->trigger_manual_poll();

        // Determine HTTP status code based on result
        $status_code = ! empty( $result['success'] ) ? 200 : 500;

        return new WP_REST_Response( $result, $status_code );
    }
}
