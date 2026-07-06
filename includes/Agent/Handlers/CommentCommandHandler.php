<?php
/**
 * Comment commands.
 *
 * Owns: comment.moderate, comment.create, comment.delete.
 *
 * Identical behaviour to the legacy CommandExecutor::execute_comment_*.
 *
 * @package Hubbee\Agent\Handlers
 */

namespace Hubbee\Agent\Handlers;

use Hubbee\Agent\BatchContext;
use Hubbee\Agent\EventPusher;
use WP_Error;

class CommentCommandHandler implements CommandHandler {

    public static function command_types(): array {
        return [ 'comment.moderate', 'comment.create', 'comment.delete' ];
    }

    public function execute( string $type, array $payload ) {
        switch ( $type ) {
            case 'comment.moderate':
                return $this->comment_moderate( $payload );
            case 'comment.create':
                return $this->comment_create( $payload );
            case 'comment.delete':
                return $this->comment_delete( $payload );
            default:
                return new WP_Error( 'bz_unknown_comment_command', sprintf( 'Unknown comment command: %s', $type ) );
        }
    }

    private function comment_moderate( array $payload ) {
        $comment_id = absint( $payload['comment_id'] ?? 0 );
        $action     = sanitize_text_field( $payload['action'] ?? '' );

        if ( ! $comment_id ) {
            return new WP_Error( 'bz_missing_comment_id', __( 'Comment ID is missing.', 'hubbee' ) );
        }

        $valid_actions = [ 'approve', 'spam', 'trash', 'hold' ];
        if ( ! in_array( $action, $valid_actions, true ) ) {
            return new WP_Error( 'bz_invalid_action', __( 'Invalid moderation action.', 'hubbee' ) );
        }

        $comment = get_comment( $comment_id );
        if ( ! $comment ) {
            return new WP_Error( 'bz_comment_not_found', __( 'Comment not found.', 'hubbee' ) );
        }

        $status_map = [
            'approve' => 1,
            'hold'    => 0,
            'spam'    => 'spam',
            'trash'   => 'trash',
        ];

        $result = wp_set_comment_status( $comment_id, $status_map[ $action ] );
        if ( ! $result ) {
            return new WP_Error( 'bz_moderate_failed', __( 'Comment moderation failed.', 'hubbee' ) );
        }

        if ( ! BatchContext::is_active() ) {
            EventPusher::get_instance()->push( 'content_changed', [
                'type'       => 'comment',
                'action'     => 'moderated',
                'comment_id' => $comment_id,
                'new_status' => $action,
                'comment'    => $this->format_comment_for_event( $comment_id ),
                'timestamp'  => current_time( 'mysql' ),
            ] );
        }

        return [ 'success' => true, 'comment_id' => $comment_id, 'status' => $action ];
    }

    private function comment_create( array $payload ) {
        $post_id = absint( $payload['post_id'] ?? 0 );
        $content = wp_kses_post( $payload['content'] ?? '' );
        $status  = sanitize_text_field( $payload['status'] ?? 'approve' );
        $parent  = absint( $payload['parent'] ?? $payload['parent_id'] ?? 0 );

        if ( ! $post_id ) {
            return new WP_Error( 'bz_missing_post_id', __( 'Post ID is missing.', 'hubbee' ) );
        }
        if ( empty( $content ) ) {
            return new WP_Error( 'bz_missing_content', __( 'Comment content is missing.', 'hubbee' ) );
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error( 'bz_post_not_found', __( 'Post not found.', 'hubbee' ) );
        }

        $current_user = wp_get_current_user();
        $user_id      = $current_user->ID ?: 0;

        $author       = sanitize_text_field( $payload['author'] ?? ( $current_user->display_name ?: 'Admin' ) );
        $author_email = sanitize_email( $payload['author_email'] ?? ( $current_user->user_email ?: get_option( 'admin_email' ) ) );
        $author_url   = esc_url_raw( $payload['author_url'] ?? '' );

        $approved_map = [
            'approve' => 1,
            'hold'    => 0,
            'spam'    => 'spam',
            'trash'   => 'trash',
        ];
        $approved = $approved_map[ $status ] ?? 1;

        $comment_id = wp_insert_comment( [
            'comment_post_ID'      => $post_id,
            'comment_content'      => $content,
            'comment_author'       => $author,
            'comment_author_email' => $author_email,
            'comment_author_url'   => $author_url,
            'comment_parent'       => $parent,
            'comment_approved'     => $approved,
            'user_id'              => $user_id,
        ] );

        if ( ! $comment_id ) {
            return new WP_Error( 'bz_comment_failed', __( 'Comment could not be created.', 'hubbee' ) );
        }

        EventPusher::get_instance()->push( 'content_changed', [
            'type'       => 'comment',
            'action'     => 'created',
            'comment_id' => $comment_id,
            'comment'    => $this->format_comment_for_event( $comment_id ),
            'timestamp'  => current_time( 'mysql' ),
        ] );

        return [ 'success' => true, 'comment_id' => $comment_id ];
    }

    private function comment_delete( array $payload ) {
        $comment_id = absint( $payload['comment_id'] ?? 0 );
        $force      = (bool) ( $payload['force'] ?? false );

        if ( ! $comment_id ) {
            return new WP_Error( 'bz_missing_comment_id', __( 'Comment ID is missing.', 'hubbee' ) );
        }

        $comment = get_comment( $comment_id );
        if ( ! $comment ) {
            return new WP_Error( 'bz_comment_not_found', __( 'Comment not found.', 'hubbee' ) );
        }

        $result = wp_delete_comment( $comment_id, $force );
        if ( ! $result ) {
            return new WP_Error( 'bz_delete_failed', __( 'Comment could not be deleted.', 'hubbee' ) );
        }

        EventPusher::get_instance()->push( 'content_changed', [
            'type'       => 'comment',
            'action'     => $force ? 'deleted' : 'trashed',
            'comment_id' => $comment_id,
            'timestamp'  => current_time( 'mysql' ),
        ] );

        return [
            'success'             => true,
            'comment_id'          => $comment_id,
            'permanently_deleted' => $force,
        ];
    }

    private function format_comment_for_event( int $comment_id ): array {
        $comment = get_comment( $comment_id );
        if ( ! $comment ) {
            return [];
        }

        $post = get_post( $comment->comment_post_ID );

        $status_map = [
            '1'     => 'approved',
            '0'     => 'pending',
            'spam'  => 'spam',
            'trash' => 'trash',
        ];
        $status = $status_map[ $comment->comment_approved ] ?? 'pending';

        return [
            'id'           => (int) $comment->comment_ID,
            'post_id'      => (int) $comment->comment_post_ID,
            'post_title'   => $post ? $post->post_title : '',
            'author'       => $comment->comment_author,
            'author_email' => $comment->comment_author_email,
            'author_url'   => $comment->comment_author_url,
            'content'      => $comment->comment_content,
            'date'         => $comment->comment_date,
            'status'       => $status,
            'parent'       => (int) $comment->comment_parent,
            'type'         => $comment->comment_type ?: 'comment',
        ];
    }
}
