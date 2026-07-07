<?php
/**
 * ElevenLabs Text-to-Speech implementation.
 *
 * Docs: https://elevenlabs.io/docs/api-reference/text-to-speech
 */

defined( 'ABSPATH' ) || exit;

class ElevenLabs_TTS extends TTS_Service {

	private $api_key;
	private $voice_id;
	private $model_id;

	public function __construct() {
		$this->api_key  = $this->require_option( 'post_to_voice_el_api_key', 'ElevenLabs API key' );
		$this->voice_id = get_option( 'post_to_voice_el_voice_id', '21m00Tcm4TlvDq8ikWAM' ); // "Rachel" default voice.
		$this->model_id = get_option( 'post_to_voice_el_model', 'eleven_multilingual_v2' );
	}

	public function generate_audio( $text, $post_id ) {
		// Keep requests reasonable; multilingual v2 accepts up to 10k chars.
		$text = mb_substr( $text, 0, 5000 );

		$endpoint = 'https://api.elevenlabs.io/v1/text-to-speech/' . rawurlencode( $this->voice_id );

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
					'xi-api-key'   => $this->api_key,
					'Accept'       => 'audio/mpeg',
				),
				'body'    => wp_json_encode( array(
					'text'     => $text,
					'model_id' => $this->model_id,
				) ),
				'timeout' => 120,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Exception( 'API request failed: ' . esc_html( $response->get_error_message() ) );
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		if ( 200 !== $response_code ) {
			$error_data    = json_decode( $response_body, true );
			$error_message = 'HTTP ' . $response_code;
			if ( isset( $error_data['detail']['message'] ) ) {
				$error_message = $error_data['detail']['message'];
			} elseif ( isset( $error_data['detail'] ) && is_string( $error_data['detail'] ) ) {
				$error_message = $error_data['detail'];
			}
			throw new Exception( 'ElevenLabs API error: ' . esc_html( $error_message ) );
		}

		if ( '' === $response_body ) {
			throw new Exception( 'No audio content in response' );
		}

		// Response body is the raw MP3 stream.
		return $this->save_audio_file( $response_body, $post_id );
	}
}
