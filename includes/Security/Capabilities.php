<?php
/**
 * Capabilities management
 *
 * @package Hubbee\Security
 */

namespace Hubbee\Security;

class Capabilities {

    /**
     * Custom capability for managing Hubbee settings
     */
    const MANAGE_SETTINGS = 'bz_manage_settings';

    /**
     * Initialize capabilities
     */
    public function init(): void {
        // Capabilities are added on activation, not on every load
    }

    /**
     * Add capabilities to roles
     */
    public static function add_capabilities(): void {
        // Add to administrator
        $admin = get_role( 'administrator' );
        if ( $admin ) {
            $admin->add_cap( self::MANAGE_SETTINGS );
        }
    }

    /**
     * Remove capabilities from roles
     */
    public static function remove_capabilities(): void {
        // Remove from administrator
        $admin = get_role( 'administrator' );
        if ( $admin ) {
            $admin->remove_cap( self::MANAGE_SETTINGS );
        }
    }

    /**
     * Check if current user can manage Hubbee settings
     *
     * @return bool
     */
    public static function current_user_can_manage(): bool {
        return current_user_can( self::MANAGE_SETTINGS ) || current_user_can( 'manage_options' );
    }
}
