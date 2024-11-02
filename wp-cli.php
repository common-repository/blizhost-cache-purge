<?php

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

// Bail if WP-CLI is not present
if ( ! defined( 'WP_CLI' ) ) {
	return;
}

/**
 * Purges CloudCache using WP-CLI commands.
 */
class WP_CLI_BlizCloudCache_Purge_Command extends WP_CLI_Command {

	/**
	 * Instance of the BlizCloudCachePurger class.
	 *
	 * @var BlizCloudCachePurger
	 */
	private $ccache_purge;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->ccache_purge = new BlizCloudCachePurger();
	}

	/**
	 * Forces a CloudCache purge of the specified URL or the entire site.
	 *
	 * ## OPTIONS
	 *
	 * [<url>]
	 * : The URL to purge from the cache. If omitted, the entire cache will be purged.
	 *
	 * [--wildcard]
	 * : Purge using a wildcard, purging all URLs under the specified path.
	 *
	 * ## EXAMPLES
	 *
	 *    wp ccache purge
	 *
	 *    wp ccache purge http://example.com/wp-content/themes/twentyeleventy/style.css
	 *
	 *    wp ccache purge "/wp-content/themes/twentysixty/style.css"
	 *
	 *    wp ccache purge http://example.com/wp-content/themes/ --wildcard
	 *
	 *    wp ccache purge "/wp-content/themes/" --wildcard
	 *
	 * @synopsis [<url>] [--wildcard]
	 *
	 * @param array $args        The URL argument.
	 * @param array $assoc_args  The associative arguments.
	 */
	public function purge( $args, $assoc_args ) {
		// Set the URL/path
		$url = '';
		if ( ! empty( $args ) ) {
			$url = $args[0];
		}

		// If the URL argument is empty, treat this as a full purge
		if ( empty( $url ) ) {
			$this->ccache_purge->do_full_purge = true;
		} else {
			// Make sure the URL is a full URL
			if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
				$url = home_url( '/' . ltrim( $url, '/' ) );
			}

			// If wildcard is set, append '.*' to the path
			if ( isset( $assoc_args['wildcard'] ) ) {
				// Parse the URL to manipulate the path
				$parsed_url = wp_parse_url( $url );
				$path = isset( $parsed_url['path'] ) ? $parsed_url['path'] : '';

				// Ensure the path ends with '/' before appending '.*'
				if ( substr( $path, -1 ) !== '/' ) {
					$path .= '/';
				}
				$path .= '.*';

				// Reconstruct the URL with the modified path
				$url = ( isset( $parsed_url['scheme'] ) ? $parsed_url['scheme'] . '://' : '' )
					 . ( isset( $parsed_url['host'] ) ? $parsed_url['host'] : '' )
					 . $path;
			}

			// Add the URL to the purge list
			$this->ccache_purge->purgeUrls[] = $url;
		}

		// Execute the purge
		$this->ccache_purge->blizexecutePurge();

		WP_CLI::success( 'The CloudCache was purged.' );
	}
}

WP_CLI::add_command( 'ccache', 'WP_CLI_BlizCloudCache_Purge_Command' );
