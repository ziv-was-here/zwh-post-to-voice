<?php
/**
 * Amazon Polly Text-to-Speech implementation.
 *
 * Uses the Polly REST API directly with AWS Signature Version 4 signing —
 * no AWS SDK required. Needs an IAM user with the polly:SynthesizeSpeech
 * permission.
 */

defined( 'ABSPATH' ) || exit;

class Amazon_Polly_TTS extends TTS_Service {

	private $access_key;
	private $secret_key;
	private $region;
	private $voice_id;
	private $engine;

	public function __construct() {
		$this->access_key = $this->require_option( 'post_to_voice_aws_access_key', 'AWS Access Key ID' );
		$this->secret_key = $this->require_option( 'post_to_voice_aws_secret_key', 'AWS Secret Access Key' );
		$this->region     = get_option( 'post_to_voice_aws_region', 'us-east-1' );
		$this->voice_id   = get_option( 'post_to_voice_aws_voice', 'Joanna' );
		$this->engine     = get_option( 'post_to_voice_aws_engine', 'neural' );
	}

	public function generate_audio( $text, $post_id ) {
		// Polly SynthesizeSpeech limit: 3000 billed characters for neural/standard text input.
		$text = mb_substr( $text, 0, 2900 );

		$host    = 'polly.' . $this->region . '.amazonaws.com';
		$path    = '/v1/speech';
		$payload = wp_json_encode( array(
			'OutputFormat' => 'mp3',
			'Text'         => $text,
			'TextType'     => 'text',
			'VoiceId'      => $this->voice_id,
			'Engine'       => $this->engine,
		) );

		$now       = time();
		$amz_date  = gmdate( 'Ymd\THis\Z', $now );
		$datestamp = gmdate( 'Ymd', $now );

		// ------------------------------------------------------------------
		// AWS Signature Version 4
		// ------------------------------------------------------------------
		$canonical_headers = 'content-type:application/json' . "\n"
			. 'host:' . $host . "\n"
			. 'x-amz-date:' . $amz_date . "\n";
		$signed_headers = 'content-type;host;x-amz-date';

		$canonical_request = implode( "\n", array(
			'POST',
			$path,
			'', // query string
			$canonical_headers,
			$signed_headers,
			hash( 'sha256', $payload ),
		) );

		$credential_scope = $datestamp . '/' . $this->region . '/polly/aws4_request';
		$string_to_sign   = implode( "\n", array(
			'AWS4-HMAC-SHA256',
			$amz_date,
			$credential_scope,
			hash( 'sha256', $canonical_request ),
		) );

		$k_date    = hash_hmac( 'sha256', $datestamp, 'AWS4' . $this->secret_key, true );
		$k_region  = hash_hmac( 'sha256', $this->region, $k_date, true );
		$k_service = hash_hmac( 'sha256', 'polly', $k_region, true );
		$k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service, true );
		$signature = hash_hmac( 'sha256', $string_to_sign, $k_signing );

		$authorization = sprintf(
			'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
			$this->access_key,
			$credential_scope,
			$signed_headers,
			$signature
		);

		$response = wp_remote_post(
			'https://' . $host . $path,
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'X-Amz-Date'    => $amz_date,
					'Authorization' => $authorization,
				),
				'body'    => $payload,
				'timeout' => 60,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Exception( 'API request failed: ' . esc_html( $response->get_error_message() ) );
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		if ( 200 !== $response_code ) {
			$error_data    = json_decode( $response_body, true );
			$error_message = isset( $error_data['message'] ) ? $error_data['message'] : 'HTTP ' . $response_code;
			throw new Exception( 'Amazon Polly API error: ' . esc_html( $error_message ) );
		}

		if ( '' === $response_body ) {
			throw new Exception( 'No audio content in response' );
		}

		// Response body is the raw MP3 stream.
		return $this->save_audio_file( $response_body, $post_id );
	}
}
