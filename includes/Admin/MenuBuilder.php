<?php
/**
 * Menu Builder - Register admin menus
 *
 * @package Hubbee\Admin
 */

namespace Hubbee\Admin;

use Hubbee\Admin\Pages\SettingsPage;

class MenuBuilder {

    /**
     * Menu icon — branded Hubbee hexagon mark as a monochrome SVG data-URI.
     *
     * WP expects monochrome menu icons; the fill is set to #a7aaad so the admin
     * menu hover/active states tint it correctly. The original full-color SVG at
     * assets/img/hubbee-icon.svg is left untouched.
     *
     * @var string
     */
    private string $icon = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAzMiAzMiIgd2lkdGg9IjMyIiBoZWlnaHQ9IjMyIj48cGF0aCBmaWxsPSIjYTdhYWFkIiBkPSJNOCAybDYgMy41djdMOCAxNmwtNi0zLjV2LTd6Ii8+PHBhdGggZmlsbD0iI2E3YWFhZCIgZD0iTTI0IDJsNiAzLjV2N0wyNCAxNmwtNi0zLjV2LTd6Ii8+PHBhdGggZmlsbD0iI2E3YWFhZCIgZD0iTTE2IDlsNiAzLjV2N0wxNiAyM2wtNi0zLjV2LTd6Ii8+PHBhdGggZmlsbD0iI2E3YWFhZCIgZD0iTTggMTZsNiAzLjV2N0w4IDMwbC02LTMuNXYtN3oiLz48cGF0aCBmaWxsPSIjYTdhYWFkIiBkPSJNMjQgMTZsNiAzLjV2N0wyNCAzMGwtNi0zLjV2LTd6Ii8+PC9zdmc+';

    /**
     * Register admin menus
     */
    public function register_menus(): void {
        // Single menu page - Settings (acts as main page)
        add_menu_page(
            __( 'Hubbee', 'hubbee' ),
            __( 'Hubbee', 'hubbee' ),
            'manage_options',
            'hubbee',
            [ $this, 'render_settings' ],
            $this->icon,
            30
        );
    }

    /**
     * Render settings page
     */
    public function render_settings(): void {
        $page = new SettingsPage();
        $page->render();
    }
}
