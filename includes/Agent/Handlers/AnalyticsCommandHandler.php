<?php
/**
 * Visitor-analytics commands (`analytics.set_enabled`).
 *
 * The site owner chooses visitor analytics when connecting the site (the
 * consent checkbox writes `bz_analytics_enabled` during enrollment). Afterwards
 * the workspace-wide toggle in the Hubbee dashboard reaches the site through
 * this command; the current state is reported back inside the `settings`
 * snapshot section so the dashboard always shows the real value.
 *
 * @package Hubbee\Agent\Handlers
 */

namespace Hubbee\Agent\Handlers;

use WP_Error;

class AnalyticsCommandHandler implements CommandHandler {

    public static function command_types(): array {
        return [ 'analytics.set_enabled' ];
    }

    public function execute( string $type, array $payload ) {
        unset( $type );

        if ( ! array_key_exists( 'enabled', $payload ) ) {
            return new WP_Error( 'bz_missing_enabled', __( 'Missing "enabled" flag.', 'hubbee' ) );
        }

        $enabled = (bool) $payload['enabled'];

        // update_option() returns false when the value is unchanged — that is
        // not a failure, so read the option back instead of trusting it.
        update_option( 'bz_analytics_enabled', $enabled ? 1 : 0 );

        return [
            'success'           => true,
            // Default matches Tracker::enqueue_tracking_script() (unset = on).
            'analytics_enabled' => (bool) get_option( 'bz_analytics_enabled', true ),
        ];
    }
}
