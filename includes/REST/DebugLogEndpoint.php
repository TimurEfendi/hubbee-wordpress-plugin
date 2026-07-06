<?php
/**
 * Debug Log Endpoint - Read WordPress debug.log
 *
 * Provides endpoint to fetch and analyze debug.log content
 * for remote monitoring via Hubbee SaaS.
 *
 * @package Hubbee\REST
 */

namespace Hubbee\REST;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class DebugLogEndpoint extends RestEndpoint {

    /**
     * Default maximum lines to read
     */
    const DEFAULT_MAX_LINES = 500;

    /**
     * Absolute maximum lines allowed
     */
    const MAX_LINES_LIMIT = 2000;

    protected function get_routes(): array {
        return [
            [ 'route' => '/debug-log',       'methods' => 'POST', 'callback' => 'get_debug_log' ],
            [ 'route' => '/debug-log/info',  'methods' => 'POST', 'callback' => 'get_debug_log_info' ],
            [ 'route' => '/debug-log/clear', 'methods' => 'POST', 'callback' => 'clear_debug_log' ],
        ];
    }

    /**
     * Get the debug log file path
     *
     * @return string|null
     */
    private function get_debug_log_path(): ?string {
        // Check if WP_DEBUG_LOG is set to a custom path
        if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) ) {
            return WP_DEBUG_LOG;
        }

        // Default location
        $default_path = WP_CONTENT_DIR . '/debug.log';

        if ( file_exists( $default_path ) ) {
            return $default_path;
        }

        return null;
    }

    /**
     * Get debug log content
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response
     */
    public function get_debug_log( WP_REST_Request $request ): WP_REST_Response {
        $body = json_decode( $request->get_body(), true );
        $max_lines = isset( $body['max_lines'] ) ? min( (int) $body['max_lines'], self::MAX_LINES_LIMIT ) : self::DEFAULT_MAX_LINES;
        $filter = $body['filter'] ?? null; // 'error', 'warning', 'notice', 'deprecated', or null for all
        $search = $body['search'] ?? null; // Search string

        $log_path = $this->get_debug_log_path();

        // Check debug mode
        $debug_enabled = defined( 'WP_DEBUG' ) && WP_DEBUG;
        $debug_log_enabled = defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;

        if ( ! $debug_enabled || ! $debug_log_enabled ) {
            return new WP_REST_Response(
                [
                    'success'          => true,
                    'debug_enabled'    => $debug_enabled,
                    'debug_log_enabled'=> $debug_log_enabled,
                    'exists'           => false,
                    'message'          => 'Debug logging is not enabled. Set WP_DEBUG and WP_DEBUG_LOG to true in wp-config.php.',
                    'entries'          => [],
                    'stats'            => $this->get_empty_stats(),
                ],
                200
            );
        }

        if ( ! $log_path || ! file_exists( $log_path ) ) {
            return new WP_REST_Response(
                [
                    'success'          => true,
                    'debug_enabled'    => $debug_enabled,
                    'debug_log_enabled'=> $debug_log_enabled,
                    'exists'           => false,
                    'path'             => $log_path ?: WP_CONTENT_DIR . '/debug.log',
                    'message'          => 'Debug log file does not exist yet.',
                    'entries'          => [],
                    'stats'            => $this->get_empty_stats(),
                ],
                200
            );
        }

        // Get file info
        $file_size = filesize( $log_path );
        $modified_time = filemtime( $log_path );

        // Read the last N lines
        $entries = $this->read_log_entries( $log_path, $max_lines, $filter, $search );

        // Calculate stats from entries
        $stats = $this->calculate_stats( $entries );

        return new WP_REST_Response(
            [
                'success'          => true,
                'timestamp'        => current_time( 'mysql' ),
                'debug_enabled'    => $debug_enabled,
                'debug_log_enabled'=> $debug_log_enabled,
                'exists'           => true,
                'path'             => $log_path,
                'size_bytes'       => $file_size,
                'size_formatted'   => size_format( $file_size ),
                'modified_at'      => wp_date( 'Y-m-d H:i:s', $modified_time ),
                'entries'          => $entries,
                'entries_count'    => count( $entries ),
                'max_lines'        => $max_lines,
                'filter'           => $filter,
                'search'           => $search,
                'stats'            => $stats,
            ],
            200
        );
    }

    /**
     * Get debug log info without content
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response
     */
    public function get_debug_log_info( WP_REST_Request $request ): WP_REST_Response {
        $log_path = $this->get_debug_log_path();

        $debug_enabled = defined( 'WP_DEBUG' ) && WP_DEBUG;
        $debug_log_enabled = defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;
        $debug_display = defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY;

        $info = [
            'success'           => true,
            'timestamp'         => current_time( 'mysql' ),
            'debug_enabled'     => $debug_enabled,
            'debug_log_enabled' => $debug_log_enabled,
            'debug_display'     => $debug_display,
            'exists'            => false,
            'path'              => $log_path ?: WP_CONTENT_DIR . '/debug.log',
            'size_bytes'        => 0,
            'size_formatted'    => '0 B',
            'modified_at'       => null,
            'line_count'        => 0,
            'writable'          => false,
        ];

        if ( $log_path && file_exists( $log_path ) ) {
            $file_size = filesize( $log_path );
            $modified_time = filemtime( $log_path );

            // Count lines (approximate for large files)
            $line_count = $this->count_lines( $log_path );

            $info['exists'] = true;
            $info['size_bytes'] = $file_size;
            $info['size_formatted'] = size_format( $file_size );
            $info['modified_at'] = wp_date( 'Y-m-d H:i:s', $modified_time );
            $info['line_count'] = $line_count;
            $info['writable'] = is_writable( $log_path );
        }

        return new WP_REST_Response( $info, 200 );
    }

    /**
     * Clear the debug log
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public function clear_debug_log( WP_REST_Request $request ) {
        $log_path = $this->get_debug_log_path();

        if ( ! $log_path || ! file_exists( $log_path ) ) {
            return new WP_REST_Response(
                [
                    'success' => true,
                    'message' => 'Debug log does not exist, nothing to clear.',
                ],
                200
            );
        }

        if ( ! is_writable( $log_path ) ) {
            return new WP_Error(
                'not_writable',
                'Debug log file is not writable.',
                [ 'status' => 403 ]
            );
        }

        // Get size before clearing for logging
        $previous_size = filesize( $log_path );

        // Clear the file
        $result = file_put_contents( $log_path, '' );

        if ( $result === false ) {
            return new WP_Error(
                'clear_failed',
                'Failed to clear the debug log.',
                [ 'status' => 500 ]
            );
        }

        return new WP_REST_Response(
            [
                'success'       => true,
                'message'       => 'Debug log cleared successfully.',
                'previous_size' => $previous_size,
                'cleared_at'    => current_time( 'mysql' ),
            ],
            200
        );
    }

    /**
     * Read log entries from file
     *
     * @param string      $path      File path.
     * @param int         $max_lines Maximum lines to read.
     * @param string|null $filter    Filter type.
     * @param string|null $search    Search string.
     * @return array
     */
    private function read_log_entries( string $path, int $max_lines, ?string $filter, ?string $search ): array {
        $entries = [];
        $lines = [];

        // Read file in reverse (last lines first)
        $file = new \SplFileObject( $path, 'r' );
        $file->seek( PHP_INT_MAX ); // Go to end
        $total_lines = $file->key();

        // Calculate start position
        $start = max( 0, $total_lines - ( $max_lines * 3 ) ); // Read more lines initially for filtering

        $file->seek( $start );

        while ( ! $file->eof() ) {
            $line = trim( $file->fgets() );
            if ( ! empty( $line ) ) {
                $lines[] = $line;
            }
        }

        // Parse entries (newest first)
        $lines = array_reverse( $lines );
        $current_entry = null;

        foreach ( $lines as $line ) {
            // Check if this is a new log entry (starts with timestamp)
            if ( preg_match( '/^\[(\d{2}-\w{3}-\d{4}\s+\d{2}:\d{2}:\d{2}\s+\w+)\]/', $line, $matches ) ) {
                // Save previous entry
                if ( $current_entry !== null ) {
                    $parsed = $this->parse_entry( $current_entry );
                    if ( $this->should_include_entry( $parsed, $filter, $search ) ) {
                        $entries[] = $parsed;
                        if ( count( $entries ) >= $max_lines ) {
                            break;
                        }
                    }
                }
                $current_entry = $line;
            } elseif ( $current_entry !== null ) {
                // Append to current entry (multi-line log)
                $current_entry .= "\n" . $line;
            }
        }

        // Don't forget the last entry
        if ( $current_entry !== null && count( $entries ) < $max_lines ) {
            $parsed = $this->parse_entry( $current_entry );
            if ( $this->should_include_entry( $parsed, $filter, $search ) ) {
                $entries[] = $parsed;
            }
        }

        return $entries;
    }

    /**
     * Parse a log entry
     *
     * @param string $entry Raw log entry.
     * @return array
     */
    private function parse_entry( string $entry ): array {
        $parsed = [
            'raw'       => $entry,
            'timestamp' => null,
            'datetime'  => null,
            'level'     => 'unknown',
            'message'   => $entry,
            'file'      => null,
            'line'      => null,
        ];

        // Extract timestamp
        if ( preg_match( '/^\[(\d{2}-\w{3}-\d{4}\s+\d{2}:\d{2}:\d{2}\s+\w+)\]/', $entry, $matches ) ) {
            $parsed['timestamp'] = $matches[1];
            // Try to parse to standard format
            $datetime = \DateTime::createFromFormat( 'd-M-Y H:i:s e', $matches[1] );
            if ( $datetime ) {
                $parsed['datetime'] = $datetime->format( 'Y-m-d H:i:s' );
            }
            $entry = trim( substr( $entry, strlen( $matches[0] ) ) );
        }

        // Detect level
        if ( stripos( $entry, 'PHP Fatal error' ) !== false ) {
            $parsed['level'] = 'fatal';
        } elseif ( stripos( $entry, 'PHP Parse error' ) !== false ) {
            $parsed['level'] = 'parse';
        } elseif ( stripos( $entry, 'PHP Warning' ) !== false ) {
            $parsed['level'] = 'warning';
        } elseif ( stripos( $entry, 'PHP Notice' ) !== false ) {
            $parsed['level'] = 'notice';
        } elseif ( stripos( $entry, 'PHP Deprecated' ) !== false ) {
            $parsed['level'] = 'deprecated';
        } elseif ( stripos( $entry, 'PHP Strict' ) !== false ) {
            $parsed['level'] = 'strict';
        } elseif ( stripos( $entry, 'error' ) !== false ) {
            $parsed['level'] = 'error';
        } elseif ( stripos( $entry, 'warning' ) !== false ) {
            $parsed['level'] = 'warning';
        }

        // Extract file and line
        if ( preg_match( '/in\s+([^\s]+)\s+on\s+line\s+(\d+)/', $entry, $matches ) ) {
            $parsed['file'] = $matches[1];
            $parsed['line'] = (int) $matches[2];
        } elseif ( preg_match( '/in\s+([^\s]+):(\d+)/', $entry, $matches ) ) {
            $parsed['file'] = $matches[1];
            $parsed['line'] = (int) $matches[2];
        }

        $parsed['message'] = $entry;

        return $parsed;
    }

    /**
     * Check if entry should be included based on filter and search
     *
     * @param array       $entry  Parsed entry.
     * @param string|null $filter Filter type.
     * @param string|null $search Search string.
     * @return bool
     */
    private function should_include_entry( array $entry, ?string $filter, ?string $search ): bool {
        // Apply level filter
        if ( $filter !== null ) {
            $filter_levels = [
                'error'      => [ 'error', 'fatal', 'parse' ],
                'warning'    => [ 'warning' ],
                'notice'     => [ 'notice' ],
                'deprecated' => [ 'deprecated', 'strict' ],
            ];

            if ( isset( $filter_levels[ $filter ] ) ) {
                if ( ! in_array( $entry['level'], $filter_levels[ $filter ], true ) ) {
                    return false;
                }
            }
        }

        // Apply search filter
        if ( $search !== null && $search !== '' ) {
            if ( stripos( $entry['raw'], $search ) === false ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Calculate statistics from entries
     *
     * @param array $entries Parsed entries.
     * @return array
     */
    private function calculate_stats( array $entries ): array {
        $stats = [
            'total'      => count( $entries ),
            'fatal'      => 0,
            'error'      => 0,
            'warning'    => 0,
            'notice'     => 0,
            'deprecated' => 0,
            'other'      => 0,
        ];

        foreach ( $entries as $entry ) {
            switch ( $entry['level'] ) {
                case 'fatal':
                case 'parse':
                    $stats['fatal']++;
                    break;
                case 'error':
                    $stats['error']++;
                    break;
                case 'warning':
                    $stats['warning']++;
                    break;
                case 'notice':
                    $stats['notice']++;
                    break;
                case 'deprecated':
                case 'strict':
                    $stats['deprecated']++;
                    break;
                default:
                    $stats['other']++;
            }
        }

        return $stats;
    }

    /**
     * Get empty stats array
     *
     * @return array
     */
    private function get_empty_stats(): array {
        return [
            'total'      => 0,
            'fatal'      => 0,
            'error'      => 0,
            'warning'    => 0,
            'notice'     => 0,
            'deprecated' => 0,
            'other'      => 0,
        ];
    }

    /**
     * Count lines in file
     *
     * @param string $path File path.
     * @return int
     */
    private function count_lines( string $path ): int {
        $file_size = filesize( $path );

        // For small files, count exactly
        if ( $file_size < 1048576 ) { // 1MB
            $file = new \SplFileObject( $path, 'r' );
            $file->seek( PHP_INT_MAX );
            return $file->key() + 1;
        }

        // For large files, estimate
        $sample_size = 10240; // 10KB sample
        $handle = fopen( $path, 'r' );
        $sample = fread( $handle, $sample_size );
        fclose( $handle );

        $lines_in_sample = substr_count( $sample, "\n" );
        $bytes_per_line = $sample_size / max( 1, $lines_in_sample );

        return (int) ( $file_size / $bytes_per_line );
    }
}
