<?php
/**
 * Transporter loader.
 *
 * @package APIAPI\Transporter_cURL
 * @since 1.0.0
 */

if ( ! function_exists( 'apiapi_register_transporter_curl' ) ) {

	/**
	 * Registers the transporter for cURL.
	 *
	 * It is stored in a global if the API-API has not yet been loaded.
	 *
	 * @since 1.0.0
	 */
	function apiapi_register_transporter_curl() {
		if ( function_exists( 'apiapi_manager' ) ) {
			apiapi_manager()->transporters()->register( 'curl', 'APIAPI\Transporter_cURL\Transporter_cURL' );
		} else {
			if ( ! isset( $GLOBALS['_apiapi_transporters_loader'] ) ) {
				$GLOBALS['_apiapi_transporters_loader'] = array();
			}

			$GLOBALS['_apiapi_transporters_loader']['curl'] = 'APIAPI\Transporter_cURL\Transporter_cURL';
		}
	}

	apiapi_register_transporter_curl();

}
