<?php
/**
 * Abstract base for Hubbee REST endpoints.
 *
 * Every endpoint under `includes/REST/` historically duplicated the same
 * register() / OPTIONS-preflight / HMAC-permission scaffolding. This base
 * class collapses that boilerplate to a few template hooks:
 *
 *   - get_route(): the route segment (e.g. '/push')
 *   - get_methods(): WP_REST methods string ('POST', 'GET', etc.)
 *   - handle_request(WP_REST_Request): the actual handler
 *   - get_args(): optional REST schema for `args`
 *   - get_permission_callback(): optional override; defaults to HMAC
 *
 * Endpoints opt in by extending this class. Migration is incremental —
 * existing endpoints keep working until they're rewritten one bundle at a time.
 *
 * @package Hubbee\REST
 */

namespace Hubbee\REST;

use Hubbee\SaaS\SignatureValidator;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

abstract class RestEndpoint {

    /**
     * Single-route convenience: route segment under RestController::NAMESPACE.
     * Override this in single-route subclasses; multi-route subclasses
     * override get_routes() instead and can leave this as the default.
     */
    protected function get_route(): string {
        return '';
    }

    /**
     * Single-route convenience: HTTP method ('POST' / 'GET' / etc.).
     * Override in single-route subclasses; multi-route subclasses leave
     * this as the default.
     */
    protected function get_methods(): string {
        return 'GET';
    }

    /**
     * Process the request. Single-route subclasses must implement this;
     * multi-route subclasses can leave this as a stub if every route maps
     * to a different callback via get_routes().
     *
     * @param WP_REST_Request $request The REST request.
     * @return mixed
     */
    public function handle_request( WP_REST_Request $request ) {
        unset( $request );
        return new \WP_Error( 'bz_no_handler', 'No request handler defined for this endpoint.', [ 'status' => 500 ] );
    }

    /**
     * Endpoint argument schema for register_rest_route's `args`. Subclasses
     * override when they want WP-side parameter validation/sanitization.
     *
     * @return array
     */
    protected function get_args(): array {
        return [];
    }

    /**
     * Permission callback. Default is HMAC signature validation (matches the
     * historical SaaS → WP push pattern). Subclasses override to use
     * `'__return_true'` for public endpoints or `current_user_can(...)` for
     * admin endpoints.
     *
     * @return callable
     */
    protected function get_permission_callback(): callable {
        return [ $this, 'check_signature_permission' ];
    }

    /**
     * Default HMAC-signature permission callback. Kept as an instance method so
     * tests can swap the validator via subclass.
     *
     * @param WP_REST_Request $request REST request.
     * @return bool|\WP_Error
     */
    public function check_signature_permission( WP_REST_Request $request ) {
        $validator = new SignatureValidator();
        return $validator->validate( $request );
    }

    /**
     * Default OPTIONS pre-flight responder. Returns 204; the actual CORS
     * headers + origin allowlisting are handled by `Hubbee\REST\CorsHandler`
     * earlier in the request lifecycle, so by the time this runs the request
     * has already been allowlisted.
     *
     * @return WP_REST_Response
     */
    public function handle_preflight(): WP_REST_Response {
        return new WP_REST_Response( null, 204 );
    }

    /**
     * Multi-route subclasses override this to expose a list of routes the
     * single class owns (e.g. ContentEndpoint owns 9 routes for posts, pages,
     * media, taxonomies, ...). Each entry can be:
     *
     *   ['route' => '/foo', 'methods' => 'GET', 'callback' => 'list_foo']
     *   ['route' => '/foo/(?P<slug>[a-z0-9-]+)', 'methods' => 'POST', 'callback' => 'get_foo', 'args' => [...]]
     *   ['route' => '/foo', 'methods' => 'POST', 'callback' => 'create_foo', 'permission_callback' => '__return_true']
     *
     * Defaults: callback resolves on $this, permission_callback uses
     * get_permission_callback(), args defaults to []. OPTIONS pre-flight is
     * registered automatically per route.
     *
     * Default implementation builds a single-route list from the legacy
     * get_route()/get_methods()/get_args() trio, so existing migrations
     * stay valid without changes.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function get_routes(): array {
        $route = $this->get_route();
        if ( '' === $route ) {
            // Subclass forgot to override either get_route() or get_routes() —
            // surface that loud at boot rather than register a broken route.
            return [];
        }
        return [
            [
                'route'    => $route,
                'methods'  => $this->get_methods(),
                'callback' => 'handle_request',
                'args'     => $this->get_args(),
            ],
        ];
    }

    /**
     * Register every route this endpoint owns plus its OPTIONS twin. Each
     * route gets its own preflight registration so CORS preflight works for
     * sub-routes too (e.g. `/themes/{slug}` in a multi-route class).
     */
    public function register(): void {
        $default_perm = $this->get_permission_callback();

        foreach ( $this->get_routes() as $spec ) {
            $route    = $spec['route'];
            $callback = $spec['callback'] ?? 'handle_request';
            $perm     = $spec['permission_callback'] ?? $default_perm;
            $methods  = $spec['methods'] ?? 'GET';
            $args     = $spec['args'] ?? [];

            register_rest_route(
                RestController::NAMESPACE,
                $route,
                [
                    [
                        'methods'             => $methods,
                        'callback'            => is_string( $callback ) ? [ $this, $callback ] : $callback,
                        'permission_callback' => $perm,
                        'args'                => $args,
                    ],
                    [
                        'methods'             => 'OPTIONS',
                        'callback'            => [ $this, 'handle_preflight' ],
                        'permission_callback' => '__return_true',
                    ],
                ]
            );
        }
    }
}
