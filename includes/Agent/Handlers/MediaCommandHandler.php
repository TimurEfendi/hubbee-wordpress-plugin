<?php
/**
 * Media (attachment) commands.
 *
 * Owns: media.upload, media.update, media.delete.
 *
 * Identical behaviour to the legacy CommandExecutor::execute_media_*. Both
 * upload paths (URL-based via media_sideload_image, base64 via wp_upload_bits
 * with a 10 MB cap) preserved verbatim. media.delete suppresses its
 * content_changed push during a batch (BatchContext::is_active()).
 *
 * @package Hubbee\Agent\Handlers
 */

namespace Hubbee\Agent\Handlers;

use Hubbee\Agent\BatchContext;
use Hubbee\Agent\EventPusher;
use WP_Error;

class MediaCommandHandler implements CommandHandler {

    public static function command_types(): array {
        return [ 'media.upload', 'media.update', 'media.delete' ];
    }

    public function execute( string $type, array $payload ) {
        switch ( $type ) {
            case 'media.upload':
                return $this->media_upload( $payload );
            case 'media.update':
                return $this->media_update( $payload );
            case 'media.delete':
                return $this->media_delete( $payload );
            default:
                return new WP_Error( 'bz_unknown_media_command', sprintf( 'Unknown media command: %s', $type ) );
        }
    }

    private function media_upload( array $payload ) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $title       = sanitize_text_field( $payload['title'] ?? '' );
        $alt_text    = sanitize_text_field( $payload['alt_text'] ?? '' );
        $caption     = sanitize_text_field( $payload['caption'] ?? '' );
        $description = sanitize_textarea_field( $payload['description'] ?? '' );
        $post_id     = absint( $payload['post_id'] ?? 0 );

        $attachment_id = 0;

        // Path A: URL-based upload via media_sideload_image.
        if ( ! empty( $payload['url'] ) ) {
            $url = esc_url_raw( $payload['url'] );
            hubbee_debug_log( '[Hubbee] Uploading media from URL: ' . $url );
            $attachment_id = media_sideload_image( $url, $post_id, $title, 'id' );

            if ( is_wp_error( $attachment_id ) ) {
                hubbee_debug_log( '[Hubbee] media_sideload_image failed: ' . $attachment_id->get_error_message() );
                return new WP_Error(
                    'bz_media_sideload_failed',
                    /* translators: %s: error message */
                    sprintf( __( 'Media upload from URL failed: %s', 'hubbee' ), $attachment_id->get_error_message() )
                );
            }
            hubbee_debug_log( '[Hubbee] Media uploaded from URL successfully: attachment_id=' . $attachment_id );
        }
        // Path B: base64 inline data (capped at 10 MB).
        elseif ( ! empty( $payload['data'] ) && ! empty( $payload['filename'] ) ) {
            $base64_data = $payload['data'];
            $filename    = sanitize_file_name( $payload['filename'] );
            $mime_type   = sanitize_mime_type( $payload['mime_type'] ?? '' );

            if ( strpos( $base64_data, 'base64,' ) !== false ) {
                $base64_data = explode( 'base64,', $base64_data )[1];
            }

            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding a base64-encoded media file payload from the authenticated push (binary data, not code). Strict mode returns false on invalid input, checked below.
            $decoded = base64_decode( $base64_data, true );
            if ( false === $decoded ) {
                hubbee_debug_log( '[Hubbee] Base64 decode failed for media upload: ' . $filename );
                return new WP_Error( 'bz_invalid_base64', __( 'Invalid Base64 data.', 'hubbee' ) );
            }

            $file_size = strlen( $decoded );
            if ( $file_size > 10 * 1024 * 1024 ) {
                hubbee_debug_log( '[Hubbee] Media upload file too large: ' . $file_size . ' bytes' );
                return new WP_Error( 'bz_file_too_large', __( 'File is too large (max. 10MB for Base64).', 'hubbee' ) );
            }

            hubbee_debug_log( '[Hubbee] Uploading media: ' . $filename . ' (' . $file_size . ' bytes)' );

            $upload = wp_upload_bits( $filename, null, $decoded );

            if ( ! is_array( $upload ) ) {
                hubbee_debug_log( '[Hubbee] wp_upload_bits returned non-array for: ' . $filename );
                return new WP_Error( 'bz_upload_failed', __( 'Upload failed (invalid response).', 'hubbee' ) );
            }
            if ( ! empty( $upload['error'] ) ) {
                hubbee_debug_log( '[Hubbee] wp_upload_bits error: ' . $upload['error'] );
                return new WP_Error( 'bz_upload_failed', $upload['error'] );
            }
            if ( empty( $upload['file'] ) ) {
                hubbee_debug_log( '[Hubbee] wp_upload_bits returned no file path' );
                return new WP_Error( 'bz_upload_failed', __( 'Upload failed (no file path).', 'hubbee' ) );
            }

            if ( empty( $mime_type ) ) {
                $filetype  = wp_check_filetype( $filename, null );
                $mime_type = $filetype['type'];
            }

            $attachment_data = [
                'post_mime_type' => $mime_type,
                'post_title'     => $title ?: preg_replace( '/\.[^.]+$/', '', $filename ),
                'post_content'   => $description,
                'post_excerpt'   => $caption,
                'post_status'    => 'inherit',
            ];

            $attachment_id = wp_insert_attachment( $attachment_data, $upload['file'], $post_id );
            if ( is_wp_error( $attachment_id ) ) {
                wp_delete_file( $upload['file'] );
                return $attachment_id;
            }

            $attach_data = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
            wp_update_attachment_metadata( $attachment_id, $attach_data );
        } else {
            return new WP_Error( 'bz_missing_media_data', __( 'URL or Base64 data required.', 'hubbee' ) );
        }

        if ( ! empty( $title ) ) {
            wp_update_post( [ 'ID' => $attachment_id, 'post_title' => $title ] );
        }
        if ( ! empty( $alt_text ) ) {
            update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );
        }
        if ( ! empty( $caption ) || ! empty( $description ) ) {
            wp_update_post( [
                'ID'           => $attachment_id,
                'post_excerpt' => $caption,
                'post_content' => $description,
            ] );
        }

        EventPusher::get_instance()->push( 'content_changed', [
            'type'      => 'media',
            'action'    => 'created',
            'media_id'  => $attachment_id,
            'media'     => $this->format_media_for_event( $attachment_id ),
            'timestamp' => current_time( 'mysql' ),
        ] );

        return [
            'success'  => true,
            'media_id' => $attachment_id,
            'url'      => wp_get_attachment_url( $attachment_id ),
        ];
    }

    private function media_update( array $payload ) {
        $media_id = absint( $payload['media_id'] ?? 0 );
        if ( ! $media_id ) {
            return new WP_Error( 'bz_missing_media_id', __( 'Media ID is missing.', 'hubbee' ) );
        }

        $media = get_post( $media_id );
        if ( ! $media || 'attachment' !== $media->post_type ) {
            return new WP_Error( 'bz_media_not_found', __( 'Media item not found.', 'hubbee' ) );
        }

        $post_data    = [ 'ID' => $media_id ];
        $needs_update = false;
        if ( isset( $payload['title'] ) ) {
            $post_data['post_title'] = sanitize_text_field( $payload['title'] );
            $needs_update            = true;
        }
        if ( isset( $payload['caption'] ) ) {
            $post_data['post_excerpt'] = sanitize_text_field( $payload['caption'] );
            $needs_update              = true;
        }
        if ( isset( $payload['description'] ) ) {
            $post_data['post_content'] = sanitize_textarea_field( $payload['description'] );
            $needs_update              = true;
        }

        if ( $needs_update ) {
            $result = wp_update_post( $post_data, true );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
        }

        if ( isset( $payload['alt_text'] ) ) {
            update_post_meta( $media_id, '_wp_attachment_image_alt', sanitize_text_field( $payload['alt_text'] ) );
        }

        EventPusher::get_instance()->push( 'content_changed', [
            'type'      => 'media',
            'action'    => 'updated',
            'media_id'  => $media_id,
            'media'     => $this->format_media_for_event( $media_id ),
            'timestamp' => current_time( 'mysql' ),
        ] );

        return [ 'success' => true, 'media_id' => $media_id ];
    }

    private function media_delete( array $payload ) {
        $media_id = absint( $payload['media_id'] ?? 0 );
        $force    = (bool) ( $payload['force'] ?? true );

        if ( ! $media_id ) {
            return new WP_Error( 'bz_missing_media_id', __( 'Media ID is missing.', 'hubbee' ) );
        }

        $media = get_post( $media_id );
        if ( ! $media || 'attachment' !== $media->post_type ) {
            return new WP_Error( 'bz_media_not_found', __( 'Media item not found.', 'hubbee' ) );
        }

        $result = wp_delete_attachment( $media_id, $force );
        if ( ! $result ) {
            return new WP_Error( 'bz_delete_failed', __( 'Media item could not be deleted.', 'hubbee' ) );
        }

        if ( ! BatchContext::is_active() ) {
            EventPusher::get_instance()->push( 'content_changed', [
                'type'      => 'media',
                'action'    => 'deleted',
                'media_id'  => $media_id,
                'timestamp' => current_time( 'mysql' ),
            ] );
        }

        return [ 'success' => true, 'media_id' => $media_id ];
    }

    private function format_media_for_event( int $media_id ): array {
        $media = get_post( $media_id );
        if ( ! $media ) {
            return [];
        }

        $metadata   = wp_get_attachment_metadata( $media_id );
        $dimensions = null;
        if ( ! empty( $metadata['width'] ) && ! empty( $metadata['height'] ) ) {
            $dimensions = [
                'width'  => (int) $metadata['width'],
                'height' => (int) $metadata['height'],
            ];
        }

        $file_path = get_attached_file( $media_id );
        $filesize  = $file_path && file_exists( $file_path ) ? size_format( filesize( $file_path ) ) : null;

        return [
            'id'          => $media->ID,
            'title'       => $media->post_title,
            'caption'     => $media->post_excerpt,
            'alt_text'    => get_post_meta( $media_id, '_wp_attachment_image_alt', true ),
            'description' => $media->post_content,
            'date'        => $media->post_date,
            'modified'    => $media->post_modified,
            'mime_type'   => $media->post_mime_type,
            'url'         => wp_get_attachment_url( $media_id ),
            'thumbnail'   => wp_get_attachment_image_url( $media_id, 'thumbnail' ),
            'medium'      => wp_get_attachment_image_url( $media_id, 'medium' ),
            'filename'    => basename( get_attached_file( $media_id ) ),
            'filesize'    => $filesize,
            'dimensions'  => $dimensions,
            'edit_link'   => get_edit_post_link( $media, 'raw' ),
        ];
    }
}
