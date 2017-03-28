<?php
/**
 * Transporter_cURL class
 *
 * @package APIAPITransporterCURL
 * @since 1.0.0
 */

namespace APIAPI\Transporter_cURL;

use APIAPI\Core\Transporters\Transporter;
use APIAPI\Core\Exception;

if ( ! class_exists( 'APIAPI\Transporter_cURL\Transporter_cURL' ) ) {

	/**
	 * Transporter implementation for cURL.
	 *
	 * @since 1.0.0
	 */
	class Transporter_cURL extends Transporter {
		/**
		 * Contains temporary headers.
		 *
		 * @since 1.0.0
		 * @access protected
		 * @var string
		 */
		protected $headers = '';

		/**
		 * Temporarily stores whether headers have been received.
		 *
		 * @since 1.0.0
		 * @access protected
		 * @var bool
		 */
		protected $done_headers = false;

		/**
		 * Contains temporary response data.
		 *
		 * @since 1.0.0
		 * @access protected
		 * @var string
		 */
		protected $response_data = '';

		/**
		 * Temporarily counts response bytes.
		 *
		 * @since 1.0.0
		 * @access protected
		 * @var int
		 */
		protected $response_bytes = 0;

		/**
		 * Sends a request and returns the response.
		 *
		 * @since 1.0.0
		 * @access public
		 *
		 * @param APIAPI\Core\Request\Request $request The request to send.
		 * @return array The returned response as an array with 'headers', 'body',
		 *               and 'response' key. The array does not necessarily
		 *               need to include all of these keys.
		 */
		public function send_request( $request ) {
			$this->headers = '';
			$this->done_headers = false;
			$this->response_data = '';
			$this->response_bytes = 0;

			$handle = curl_init();

			curl_setopt( $handle, CURLOPT_HEADER, true );
			curl_setopt( $handle, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $handle, CURLOPT_TIMEOUT, 5 );
			curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, 5 );

			$url = $request->get_uri();

			$method = $request->get_method();
			if ( 'POST' === $method ) {
				curl_setopt( $handle, CURLOPT_POST, true );
			} elseif ( in_array( $method, array( 'PUT', 'PATCH', 'DELETE' ) ) ) {
				curl_setopt( $handle, CURLOPT_CUSTOMREQUEST, $method );
			}

			$data = $request->get_params();
			if ( ! empty( $data ) ) {
				if ( 'GET' === $method ) {
					$url = $this->build_get_query( $url, $data );
				} else {
					if ( 0 === strpos( $request->get_header( 'content-type' ), 'application/json' ) ) {
						$data = json_encode( $data );
						if ( ! $data ) {
							throw new Exception( sprintf( 'The request to %s could not be sent as the data could not be JSON-encoded.', $url ) );
						}
					} else {
						$data = http_build_query( $data, null, '&' );
					}

					curl_setopt( $handle, CURLOPT_POSTFIELDS, $data );
				}
			}

			curl_setopt( $handle, CURLOPT_URL, $url );
			curl_setopt( $handle, CURLOPT_REFERER, $url );

			$headers = array();
			foreach ( $request->get_headers() as $key => $value ) {
				$headers[] = sprintf( '%s: %s', $key, $value );
			}
			if ( ! empty( $headers ) ) {
				curl_setopt( $handle, CURLOPT_HTTPHEADER, $headers );
			}

			curl_setopt( $handle, CURLOPT_HEADERFUNCTION, array( $this, 'stream_headers' ) );
			curl_setopt( $handle, CURLOPT_WRITEFUNCTION, array( $this, 'stream_body' ) );
			curl_setopt( $handle, CURLOPT_BUFFERSIZE, 1160 );

			curl_exec( $handle );

			if ( ( $error_code = curl_errno( $handle ) ) ) {
				$error_message = curl_error( $handle );

				curl_close( $handle );
				throw new Exception( sprintf( 'The request to %1$s could not be sent because of cURL error %2$s: %3$s', $url, $error_code, $error_message ) );
			}

			curl_close( $handle );

			$headers = $this->headers;
			if ( false === ( $separator_position = strpos( $this->response_data, $this->headers ) ) ) {
				throw new Exception( sprintf( 'The request to %s returned an invalid response without a header/body separator.', $url ) );
			}

			$body = substr( $this->response_data, $separator_position + strlen( $this->headers ) );

			$headers = preg_replace( '/\n[ \t]/', ' ', str_replace( "\r\n", "\n", $headers ) );
			$headers = explode( "\n", $headers );

			preg_match( '#^HTTP/(1\.\d)[ \t]+(\d+)#i', array_shift( $headers ), $matches );
			if ( empty( $matches ) ) {
				throw new Exception( sprintf( 'The request to %s returned an invalid response without protocol and status code.', $url ) );
			}

			$status_code = (int) $matches[2];
			if ( $status_code < 200 || $status_code >= 300 ) {
				throw new Exception( sprintf( 'The request to %1$s returned status code %2$s: %3$s', $url, $status_code, self::get_status_message( $status_code ) ) );
			}

			$headers_assoc = array();
			foreach ( $headers as $header ) {
				if ( empty( $header ) ) {
					continue;
				}

				list( $key, $value ) = explode( ':', $header, 2 );
				$value = trim( $value );
				preg_replace( '#(\s+)#i', ' ', $value );
				$headers_assoc[ $key ] = $value;
			}

			$response_data = array(
				'headers'  => $headers_assoc,
				'body'     => $body,
				'response' => array(
					'code'    => $status_code,
					'message' => self::get_status_message( $status_code ),
				),
			);

			return $response_data;
		}

		/**
		 * Receives the streamed headers.
		 *
		 * @since 1.0.0
		 * @access public
		 *
		 * @param resource $handle  cURL resource.
		 * @param string   $headers Headers string.
		 * @return int Length of provided header.
		 */
		public function stream_headers( $handle, $headers ) {
			if ( $this->done_headers ) {
				$this->headers = '';
				$this->done_headers = false;
			}

			$this->headers .= $headers;

			if ( $headers === "\r\n" ) {
				$this->done_headers = true;
			}

			return strlen( $headers );
		}

		/**
		 * Receives the streamed body.
		 *
		 * @since 1.0.0
		 * @access public
		 *
		 * @param resource $handle cURL resource.
		 * @param string   $data   Body string.
		 * @return int Length of provided body.
		 */
		public function stream_body( $handle, $data ) {
			$this->response_data .= $data;
			$this->response_bytes += strlen( $data );

			return strlen( $data );
		}

		/**
		 * Formats a URL with GET data.
		 *
		 * @since 1.0.0
		 * @access protected
		 *
		 * @param string $url  URL to use.
		 * @param array  $data Data to build query string.
		 * @return string URL with query data.
		 */
		protected function build_get_query( $url, $data ) {
			if ( ! empty( $data ) ) {
				$url_parts = parse_url( $url );

				if ( empty( $url_parts['query'] ) ) {
					$query = $url_parts['query'] = '';
				} else {
					$query = $url_parts['query'];
				}

				$query .= '&' . http_build_query( $data, null, '&' );
				$query = trim( $query, '&' );

				if ( empty( $url_parts['query'] ) ) {
					$url .= '?' . $query;
				} else {
					$url = str_replace( $url_parts['query'], $query, $url );
				}
			}

			return $url;
		}
	}

}
