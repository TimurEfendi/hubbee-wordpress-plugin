<?php
/**
 * Chunk Manager - Atomic, integrity-checked runtime-chunk IO for the
 * Element Library / Background / Text-Effect push pipeline.
 *
 * Single source of truth for two things every push endpoint needs:
 *   1. Writing a downloaded JS chunk to disk WITHOUT ever leaving a
 *      partially-written or hash-mismatched file visible (temp + atomic
 *      rename + sidecar in one step).
 *   2. Deleting a chunk together with its `.sha256` sidecar, so deletions
 *      never orphan a sidecar (Background/TextEffect endpoints previously
 *      deleted only the `.min.js`).
 *
 * The on-disk filename scheme and the chunks directory resolution are kept
 * byte-for-byte identical to the previous inline implementations — this is
 * a behaviour-preserving consolidation, not a contract change.
 *
 * @package Hubbee\Components
 */

namespace Hubbee\Components;

use WP_Error;

class ChunkManager {

    /**
     * Hard cap for a single pushed config payload (after wp_json_encode).
     * Above this we reject the push outright — a multi-MB LONGTEXT row
     * blows up the DOM on render and is never a legitimate config.
     */
    const CONFIG_MAX_BYTES = 1048576; // 1 MB.

    /**
     * Soft warning threshold for config payloads. Above this we keep going
     * but log so operators can spot configs trending toward the hard cap.
     */
    const CONFIG_WARN_BYTES = 524288; // 512 KB.

    /**
     * Resolve the shared chunks directory (wp-content/uploads/hubbee/chunks).
     *
     * Identical resolution to the previous inline code in every endpoint.
     *
     * @return string|WP_Error Absolute path (no trailing slash) or error.
     */
    public static function chunks_dir() {
        $upload_dir = wp_upload_dir();
        if ( ! empty( $upload_dir['error'] ) ) {
            return new WP_Error(
                'bz_chunk_dir_unavailable',
                __( 'Upload directory is not writable.', 'hubbee' )
            );
        }

        return $upload_dir['basedir'] . '/hubbee/chunks';
    }

    /**
     * Validate that a config payload is within the allowed size envelope.
     *
     * Encodes the config exactly as the repository will persist it
     * (wp_json_encode) and measures the byte length. Logs a warning above
     * CONFIG_WARN_BYTES and returns a 4xx WP_Error above CONFIG_MAX_BYTES.
     *
     * @param mixed  $config     Config array/object (as received from push).
     * @param string $context    Human-readable identifier for log lines
     *                           (e.g. "component:bounce-cards").
     * @return true|WP_Error True when within limits, WP_Error when too large.
     */
    public static function validate_config_size( $config, string $context = '' ) {
        // Match repository persistence: arrays/objects get json-encoded,
        // strings are stored verbatim.
        if ( is_array( $config ) || is_object( $config ) ) {
            $encoded = wp_json_encode( $config );
            $bytes   = false === $encoded ? 0 : strlen( $encoded );
        } else {
            $bytes = strlen( (string) $config );
        }

        $label = '' !== $context ? $context : 'unknown';

        if ( $bytes > self::CONFIG_MAX_BYTES ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log(
                sprintf(
                    '[Hubbee][ChunkManager] Config rejected for %s: %d bytes exceeds hard cap of %d bytes.',
                    $label,
                    $bytes,
                    self::CONFIG_MAX_BYTES
                )
            );

            return new WP_Error(
                'bz_config_too_large',
                sprintf(
                    /* translators: 1: max size in KB, 2: actual size in KB */
                    __( 'Config exceeds the maximum allowed size of %1$d KB (received %2$d KB).', 'hubbee' ),
                    (int) round( self::CONFIG_MAX_BYTES / 1024 ),
                    (int) round( $bytes / 1024 )
                ),
                [ 'status' => 413 ]
            );
        }

        if ( $bytes > self::CONFIG_WARN_BYTES ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log(
                sprintf(
                    '[Hubbee][ChunkManager] Large config for %s: %d bytes (warn threshold %d bytes).',
                    $label,
                    $bytes,
                    self::CONFIG_WARN_BYTES
                )
            );
        }

        return true;
    }

    /**
     * Atomically write a downloaded chunk to its final path.
     *
     * Flow:
     *   1. Verify the body hash against the expected SHA-256 (when given).
     *      A mismatched body never touches the final path.
     *   2. Write the body to `<final>.tmp`.
     *   3. Atomically rename the temp file onto the final path.
     *   4. Write the `.sha256` sidecar with the verified hash.
     *
     * On any failure the temp file is removed so no partial chunk lingers.
     *
     * @param string $final_path     Absolute destination path of the chunk.
     * @param string $bytes          Raw chunk body.
     * @param string $expected_sha256 Expected hash as "sha256:<hex>" ('' to skip check).
     * @return bool|WP_Error True on success, WP_Error on integrity/IO failure.
     */
    public static function write_chunk_atomic( string $final_path, string $bytes, string $expected_sha256 = '' ) {
        if ( '' === $bytes ) {
            return new WP_Error(
                'bz_chunk_empty',
                __( 'Refusing to write an empty chunk.', 'hubbee' )
            );
        }

        $actual_hash = 'sha256:' . hash( 'sha256', $bytes );

        // Integrity gate: reject a body that doesn't match the expected hash
        // BEFORE writing anything to disk.
        if ( '' !== $expected_sha256 && $actual_hash !== $expected_sha256 ) {
            return new WP_Error(
                'bz_chunk_hash_mismatch',
                __( 'Downloaded chunk failed SHA-256 integrity check.', 'hubbee' )
            );
        }

        $chunk_dir = dirname( $final_path );
        if ( ! wp_mkdir_p( $chunk_dir ) ) {
            return new WP_Error(
                'bz_chunk_dir_unwritable',
                __( 'Could not create the chunks directory.', 'hubbee' )
            );
        }

        global $wp_filesystem;
        if ( empty( $wp_filesystem ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        // Per-process unique temp name so concurrent writes to the same
        // chunk don't clobber each other's temp file before the rename.
        $tmp_path = $final_path . '.' . getmypid() . '.tmp';

        if ( ! $wp_filesystem->put_contents( $tmp_path, $bytes, FS_CHMOD_FILE ) ) {
            // Best-effort cleanup; nothing visible was written to final_path.
            if ( $wp_filesystem->exists( $tmp_path ) ) {
                $wp_filesystem->delete( $tmp_path );
            }

            return new WP_Error(
                'bz_chunk_write_failed',
                __( 'Could not write the chunk temp file.', 'hubbee' )
            );
        }

        // Atomic publish. wp_filesystem->move() with overwrite maps to
        // rename() on the direct (default) transport.
        if ( ! $wp_filesystem->move( $tmp_path, $final_path, true ) ) {
            if ( $wp_filesystem->exists( $tmp_path ) ) {
                $wp_filesystem->delete( $tmp_path );
            }

            return new WP_Error(
                'bz_chunk_rename_failed',
                __( 'Could not finalize the chunk file.', 'hubbee' )
            );
        }

        // Sidecar is written last so a reader that sees the sidecar always
        // sees a complete chunk. Failure here is non-fatal: the chunk is on
        // disk and the sidecar is recomputable from the file content.
        $wp_filesystem->put_contents( $final_path . '.sha256', $actual_hash, FS_CHMOD_FILE );

        return true;
    }

    /**
     * Delete a chunk file together with its `.sha256` sidecar.
     *
     * Mirrors the existing ComponentPushHandler deletion pattern so that
     * deletions never leave an orphaned sidecar behind.
     *
     * @param string $chunk_file Absolute path to the `.min.js` chunk.
     * @return void
     */
    public static function delete_with_sidecar( string $chunk_file ): void {
        foreach ( [ $chunk_file, $chunk_file . '.sha256' ] as $path ) {
            if ( file_exists( $path ) ) {
                wp_delete_file( $path );
            }
        }
    }

    /**
     * Acquire a short-lived, site-level mutex around a delete/reconcile loop.
     *
     * Same idea as CommandPoller's `bz_poll_lock`: a read-snapshot-then-delete
     * loop must not run concurrently with another push for the same resource
     * type, or two requests can race on the same rows/chunks.
     *
     * @param string $key TTL transient key (distinct per resource type).
     * @param int    $ttl Lock lifetime in seconds.
     * @return bool True when the lock was acquired, false when already held.
     */
    public static function acquire_lock( string $key, int $ttl = 60 ): bool {
        if ( get_transient( $key ) ) {
            return false;
        }

        set_transient( $key, true, $ttl );

        return true;
    }

    /**
     * Release a mutex acquired via acquire_lock().
     *
     * @param string $key TTL transient key.
     * @return void
     */
    public static function release_lock( string $key ): void {
        delete_transient( $key );
    }
}
