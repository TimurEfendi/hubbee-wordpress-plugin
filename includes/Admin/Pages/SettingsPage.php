<?php
/**
 * Settings Page - Hubbee Agent connection settings with 3-step onboarding
 *
 * @package Hubbee\Admin\Pages
 */

namespace Hubbee\Admin\Pages;

use Hubbee\SaaS\ConnectionManager;

class SettingsPage {

    /**
     * Connection manager
     *
     * @var ConnectionManager
     */
    private ConnectionManager $connection;

    /**
     * Constructor
     */
    public function __construct() {
        $this->connection = new ConnectionManager();
    }

    /**
     * Render the settings page
     */
    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'hubbee' ) );
        }

        $is_connected = $this->connection->is_connected();
        $status = $this->connection->get_connection_status();
        // Determine the onboarding token URL
        $onboarding_url = 'https://hubbee.io/de/onboarding';
        ?>
        <div class="wrap bz-agent-settings">
            <h1 class="bz-page-title">
                <span class="dashicons dashicons-text-page"></span>
                <?php esc_html_e( 'Hubbee Agent', 'hubbee' ); ?>
            </h1>

            <!-- Connection Status Card -->
            <div class="bz-status-card <?php echo $is_connected ? 'connected' : 'disconnected'; ?>">
                <div class="bz-status-indicator">
                    <span class="bz-status-dot"></span>
                    <span class="bz-status-label">
                        <?php
                        echo $is_connected
                            ? esc_html__( 'Connected', 'hubbee' )
                            : esc_html__( 'Not connected', 'hubbee' );
                        ?>
                    </span>
                </div>
                <?php if ( $is_connected && ! empty( $status['last_sync'] ) ) : ?>
                    <div class="bz-last-sync">
                        <span class="dashicons dashicons-update"></span>
                        <?php
                        printf(
                            /* translators: %s: last sync date */
                            esc_html__( 'Last synchronization: %s', 'hubbee' ),
                            esc_html( $status['last_sync'] )
                        );
                        ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ( ! $is_connected ) : ?>
                <!-- 3-Step Onboarding -->
                <div class="bz-onboarding">
                    <h2><?php esc_html_e( 'Connect site', 'hubbee' ); ?></h2>

                    <div class="bz-steps">
                        <!-- Step 1 -->
                        <div class="bz-step" data-step="1">
                            <div class="bz-step-number">1</div>
                            <div class="bz-step-content">
                                <h3><?php esc_html_e( 'Generate token', 'hubbee' ); ?></h3>
                                <p><?php esc_html_e( 'Log in to the Hubbee SaaS and generate an onboarding token for this site.', 'hubbee' ); ?></p>
                                <a href="<?php echo esc_url( $onboarding_url ); ?>" target="_blank" rel="noopener" class="button">
                                    <?php esc_html_e( 'Generate token', 'hubbee' ); ?>
                                    <span class="dashicons dashicons-external"></span>
                                </a>
                            </div>
                        </div>

                        <!-- Step 2 -->
                        <div class="bz-step" data-step="2">
                            <div class="bz-step-number">2</div>
                            <div class="bz-step-content">
                                <h3><?php esc_html_e( 'Enter token', 'hubbee' ); ?></h3>
                                <p><?php esc_html_e( 'Copy the generated token and paste it here.', 'hubbee' ); ?></p>
                                <input type="text"
                                       id="bz-onboarding-token"
                                       class="bz-token-input"
                                       placeholder="bz_xxxxxxxxxxxx"
                                       autocomplete="off">
                            </div>
                        </div>

                        <!-- Step 3 -->
                        <div class="bz-step" data-step="3">
                            <div class="bz-step-number">3</div>
                            <div class="bz-step-content">
                                <h3><?php esc_html_e( 'Connect', 'hubbee' ); ?></h3>
                                <p><?php esc_html_e( 'Choose whether this site should report visitor analytics, then establish the connection.', 'hubbee' ); ?></p>

                                <div class="bz-consent">
                                    <label for="bz-analytics-consent">
                                        <input type="checkbox" id="bz-analytics-consent" checked>
                                        <strong><?php esc_html_e( 'Send visitor analytics to Hubbee', 'hubbee' ); ?></strong>
                                    </label>
                                    <p class="description">
                                        <?php esc_html_e( 'Loads a small, cookieless script on your public pages and reports page path, a random per-session hash, referrer domain and user agent. No cookies, no cross-site tracking, no personal profiles. Leave this unchecked and Hubbee never loads the script; you can change it at any time in your Hubbee dashboard under workspace settings.', 'hubbee' ); ?>
                                    </p>
                                    <p class="description">
                                        <?php esc_html_e( 'If you enable this, disclose the analytics in your own privacy policy — you remain the controller for your visitors\' data.', 'hubbee' ); ?>
                                    </p>
                                </div>

                                <button type="button" class="button button-primary button-hero" id="bz-connect-btn">
                                    <?php esc_html_e( 'Establish connection', 'hubbee' ); ?>
                                </button>
                                <span class="spinner" id="bz-connect-spinner"></span>
                            </div>
                        </div>
                    </div>

                    <div id="bz-connect-message" class="bz-message hidden"></div>
                    <div id="bz-connect-working-hint" class="bz-working-hint hidden">
                        <span class="dashicons dashicons-clock"></span>
                        <?php esc_html_e( 'The connection is still being established – this may take a moment. Please wait or try again.', 'hubbee' ); ?>
                    </div>
                </div>

            <?php else : ?>
                <!-- Connected State -->
                <div class="bz-connected-info">
                    <table class="bz-info-table">
                        <tr>
                            <th><?php esc_html_e( 'Hubbee Site ID', 'hubbee' ); ?></th>
                            <td><code><?php echo esc_html( $status['site_id'] ); ?></code></td>
                        </tr>
                        <?php if ( ! empty( $status['site_name'] ) ) : ?>
                        <tr>
                            <th><?php esc_html_e( 'Site Name', 'hubbee' ); ?></th>
                            <td><?php echo esc_html( $status['site_name'] ); ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <th><?php esc_html_e( 'Connected since', 'hubbee' ); ?></th>
                            <td><?php echo esc_html( $status['enrolled_at'] ); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Visitor analytics', 'hubbee' ); ?></th>
                            <td>
                                <?php
                                // Mirror Tracker::enqueue_tracking_script() exactly —
                                // same default (unset means on) AND the same filter,
                                // so a site that forces analytics off in code sees
                                // "Off" here instead of a contradicting "On". This is
                                // the screen an owner or a reviewer checks to find out
                                // what the site really does.
                                $analytics_on = (bool) get_option( 'bz_analytics_enabled', true );
                                $analytics_on = (bool) apply_filters( 'hubbee_analytics_enabled', $analytics_on );
                                echo $analytics_on
                                    ? esc_html__( 'On — switch it off in your Hubbee dashboard under workspace settings.', 'hubbee' )
                                    : esc_html__( 'Off — no tracking script is loaded on this site.', 'hubbee' );
                                ?>
                            </td>
                        </tr>
                    </table>
                </div>
            <?php endif; ?>

            <!-- Advanced Settings (Collapsible) -->
            <details class="bz-advanced-section">
                <summary>
                    <span class="dashicons dashicons-admin-tools"></span>
                    <?php esc_html_e( 'Advanced settings', 'hubbee' ); ?>
                </summary>

                <div class="bz-advanced-content">
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Local Site ID', 'hubbee' ); ?></th>
                            <td><code><?php echo esc_html( get_option( 'bz_site_id', 'N/A' ) ); ?></code></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'REST API URL', 'hubbee' ); ?></th>
                            <td><code><?php echo esc_url( rest_url( 'bz/v1/' ) ); ?></code></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Plugin Version', 'hubbee' ); ?></th>
                            <td><?php echo esc_html( BZ_VERSION ); ?></td>
                        </tr>
                    </table>

                    <?php if ( $is_connected ) : ?>
                        <hr>
                        <h3><?php esc_html_e( 'Check connection', 'hubbee' ); ?></h3>
                        <p class="description">
                            <?php esc_html_e( 'Tests whether the current connection to the Hubbee SaaS is working.', 'hubbee' ); ?>
                        </p>
                        <button type="button" class="button" id="bz-test-connection-btn">
                            <?php esc_html_e( 'Test connection', 'hubbee' ); ?>
                        </button>
                        <span class="spinner" id="bz-test-spinner"></span>
                        <div id="bz-test-message" class="bz-message hidden"></div>
                        <div id="bz-test-working-hint" class="bz-working-hint hidden">
                            <span class="dashicons dashicons-clock"></span>
                            <?php esc_html_e( 'The test is still running – please be patient for a moment or try again.', 'hubbee' ); ?>
                        </div>


                        <hr>
                        <h3><?php esc_html_e( 'Reset connection', 'hubbee' ); ?></h3>
                        <p class="description">
                            <?php esc_html_e( 'Disconnects from the Hubbee SaaS. You will need to reconnect the site afterwards.', 'hubbee' ); ?>
                        </p>
                        <button type="button" class="button" id="bz-disconnect-btn">
                            <?php esc_html_e( 'Disconnect', 'hubbee' ); ?>
                        </button>
                    <?php endif; ?>

                </div>
            </details>
        </div>
        <?php
    }
}
