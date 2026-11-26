<?php
/**
 * Routes and serves the SocialSpec documents.
 *
 * @link https://socialspec.org/
 *
 * @package Socialspec
 */

declare( strict_types = 1 );

namespace Socialspec;

use Socialspec\Documents\About;
use Socialspec\Documents\Contact;
use Socialspec\Documents\Following;

/**
 * SocialSpec class.
 */
final class Socialspec {

	/**
	 * Registers the hooks.
	 */
	public static function init(): void {
		\add_action( 'init', array( self::class, 'add_rewrite_rules' ) );
		\add_filter( 'query_vars', array( self::class, 'query_vars' ) );
		// Last, after redirect_canonical() and the access gates other plugins run on the template hooks.
		\add_filter( 'template_include', array( self::class, 'serve' ), PHP_INT_MAX );
		\add_filter( 'exit_on_http_head', array( self::class, 'exit_on_http_head' ) );
		\add_filter( 'redirect_canonical', array( self::class, 'redirect_canonical' ) );

		// Apps read the feed next to the documents, so the core feeds get CORS too.
		foreach ( array( 'rdf', 'rss', 'rss2', 'atom' ) as $feed ) {
			\add_action( "do_feed_{$feed}", array( self::class, 'send_cors_header' ), 1 );
		}
	}

	/**
	 * Allows browser apps on other origins to read the response.
	 */
	public static function send_cors_header(): void {
		if ( ! \headers_sent() ) {
			\header( 'Access-Control-Allow-Origin: *' );
		}
	}

	/**
	 * The documents, keyed by their file name in /.well-known/.
	 *
	 * @return array<string, Document> The documents.
	 */
	public static function get_documents(): array {
		/**
		 * Filters the documents served at /.well-known/.
		 *
		 * Remove an entry to disable an API, or add a `Socialspec\Document`.
		 * Rewrite rules are generated from the keys, so flush them after a change.
		 *
		 * @param array<string, Document> $documents File name => document.
		 */
		$documents = \apply_filters(
			'socialspec_documents',
			array(
				'following.opml' => new Following(),
				'about.md'       => new About(),
				'contact.vcf'    => new Contact(),
			)
		);

		return \array_filter( (array) $documents, static fn ( $document ): bool => $document instanceof Document );
	}

	/**
	 * Adds the /.well-known/ rewrite rule.
	 */
	public static function add_rewrite_rules(): void {
		// WP_Rewrite matches rules with "#" as delimiter.
		$names = \array_map( static fn ( $name ): string => \preg_quote( (string) $name, '#' ), \array_keys( self::get_documents() ) );

		if ( ! $names ) {
			return;
		}

		\add_rewrite_rule( '^\.well-known/(' . \implode( '|', $names ) . ')$', 'index.php?well-known=$matches[1]', 'top' );
	}

	/**
	 * Adds the `well-known` query var, shared with the WebFinger and host-meta plugins.
	 *
	 * @param string[] $vars The public query vars.
	 *
	 * @return string[] The filtered query vars.
	 */
	public static function query_vars( array $vars ): array {
		$vars[] = 'well-known';

		return $vars;
	}

	/**
	 * Keeps the document URLs: the rewrite rule makes them a home query, which would get a trailing slash.
	 *
	 * @param string|false $redirect_url The redirect URL.
	 *
	 * @return string|false False if a document is requested.
	 */
	public static function redirect_canonical( string|false $redirect_url ): string|false {
		$name = self::get_requested_name( $GLOBALS['wp'] );

		return null !== $name && isset( self::get_documents()[ $name ] ) ? false : $redirect_url;
	}

	/**
	 * Answers HEAD requests for a document: WordPress exits before the template, so serve() would not run.
	 *
	 * @param bool $should_exit Whether to exit without loading the template.
	 *
	 * @return bool The unchanged value, if no document is requested.
	 */
	public static function exit_on_http_head( bool $should_exit ): bool {
		// Exits if a document is requested.
		self::serve( '' );

		return $should_exit;
	}

	/**
	 * Serves the requested document in place of the template.
	 *
	 * Runs after the access gates on the template hooks, so a site that
	 * restricts access, for example a private site, does not publish it.
	 *
	 * @param string $template The template to load.
	 *
	 * @return string The template, if no document is requested.
	 */
	public static function serve( string $template ): string {
		$name = self::get_requested_name( $GLOBALS['wp'] );

		if ( null === $name ) {
			return $template;
		}

		$document = self::get_documents()[ $name ] ?? null;

		// Not ours, for example /.well-known/webfinger.
		if ( null === $document ) {
			return $template;
		}

		\header( 'Access-Control-Allow-Origin: *' );

		$method = \strtoupper( \sanitize_text_field( \wp_unslash( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) );

		if ( 'OPTIONS' === $method ) {
			\status_header( 204 );
			\header( 'Access-Control-Allow-Methods: GET, HEAD, OPTIONS' );
			\header( 'Access-Control-Max-Age: 86400' );
			exit;
		}

		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			\header( 'Allow: GET, HEAD, OPTIONS' );
			self::send_error( 405, 'Method Not Allowed' );
		}

		/*
		 * A short lifetime, so caches pick up a post made private soon. Logged-in
		 * users keep the no-cache headers of WordPress, the content may be theirs.
		 */
		if ( ! \is_user_logged_in() ) {
			foreach ( \array_keys( \wp_get_nocache_headers() ) as $header ) {
				\header_remove( $header );
			}

			\header( 'Cache-Control: public, max-age=300' );
		}

		\status_header( 200 );
		\header( 'Content-Type: ' . $document->get_content_type() . '; charset=' . \get_bloginfo( 'charset' ) );
		\header( 'X-Content-Type-Options: nosniff' );
		\header( 'X-Robots-Tag: noindex' );

		/*
		 * Nothing is printed yet, so the headers can still change. HEAD is
		 * rendered too, so it gets the status of GET; the server drops the body.
		 */
		if ( ! $document->render() ) {
			self::send_error( 404, 'Not Found' );
		}

		exit;
	}

	/**
	 * The requested file name in /.well-known/.
	 *
	 * Uses the `well-known` query var of the rewrite rule, or the request path
	 * where the rule does not apply: with plain permalinks, or before the rules
	 * are flushed, e.g. on the sites of a network after network activation.
	 *
	 * @param \WP $wp The WordPress request.
	 *
	 * @return string|null The file name, or null if /.well-known/ is not requested.
	 */
	public static function get_requested_name( \WP $wp ): ?string {
		$name = $wp->query_vars['well-known'] ?? null;

		if ( \is_string( $name ) ) {
			return $name;
		}

		$path   = (string) \wp_parse_url( \wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only compared.
		$prefix = \trailingslashit( (string) \wp_parse_url( \home_url(), PHP_URL_PATH ) ) . '.well-known/';

		return \str_starts_with( $path, $prefix ) ? \substr( $path, \strlen( $prefix ) ) : null;
	}

	/**
	 * Adds the rewrite rule and flushes the rules on activation.
	 */
	public static function activate(): void {
		self::add_rewrite_rules();
		\flush_rewrite_rules( false );
	}

	/**
	 * Drops the rewrite rules on deactivation.
	 *
	 * A flush would regenerate them with this plugin's rule still registered,
	 * so they are deleted and regenerated on the next request instead.
	 */
	public static function deactivate(): void {
		\delete_option( 'rewrite_rules' );
	}

	/**
	 * Sends a plain text error and exits.
	 *
	 * @param int    $status  The HTTP status code.
	 * @param string $message The error message.
	 */
	private static function send_error( int $status, string $message ): never {
		\status_header( $status );
		\header( 'Content-Type: text/plain; charset=' . \get_bloginfo( 'charset' ) );
		echo \esc_html( $message );
		exit;
	}
}
