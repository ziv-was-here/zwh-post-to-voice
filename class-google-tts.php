<?php
/**
 * Google Cloud Text-to-Speech implementation
 */

defined( 'ABSPATH' ) || exit;

class Google_TTS extends TTS_Service {

	const API_ENDPOINT = 'https://texttospeech.googleapis.com/v1/text:synthesize';

	public function generate_audio( $text, $post_id ) {
		// Prepare request body
		$request_body = array(
			'input' => array(
				'text' => $text,
			),
			'voice' => array(
				'languageCode' => $this->language,
				'name' => $this->voice_name,
			),
			'audioConfig' => array(
				'audioEncoding' => 'MP3',
				'pitch' => 0,
				'speakingRate' => 1.0,
			),
		);

		// Make API request
		$response = wp_remote_post(
			self::API_ENDPOINT . '?key=' . urlencode( $this->api_key ),
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body' => wp_json_encode( $request_body ),
				'timeout' => 60,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Exception( 'API request failed: ' . esc_html( $response->get_error_message() ) );
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		if ( 200 !== $response_code ) {
			$error_data = json_decode( $response_body, true );
			$error_message = isset( $error_data['error']['message'] ) ? $error_data['error']['message'] : 'Unknown error';
			throw new Exception( 'Google TTS API error: ' . esc_html( $error_message ) );
		}

		$data = json_decode( $response_body, true );

		if ( ! isset( $data['audioContent'] ) ) {
			throw new Exception( 'No audio content in response' );
		}

		// Decode base64 audio
		$audio_binary = base64_decode( $data['audioContent'] );

		if ( ! $audio_binary ) {
			throw new Exception( 'Failed to decode audio content' );
		}

		// Save audio file
		$audio_url = $this->save_audio_file( $audio_binary, $post_id );

		return $audio_url;
	}
}
