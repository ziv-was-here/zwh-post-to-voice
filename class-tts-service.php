<?php
/**
 * Base TTS Service class
 */

defined( 'ABSPATH' ) || exit;

abstract class TTS_Service {
	protected $api_key;
	protected $voice_name;
	protected $language;

	public function __construct() {
		$this->api_key = get_option( 'post_to_voice_api_key', '' );
		$this->voice_name = get_option( 'post_to_voice_voice_name', 'en-US-Neural2-A' );
		$this->language = get_option( 'post_to_voice_language', 'en-US' );

		if ( ! $this->api_key ) {
			throw new Exception( 'API key not configured. Please set it in Post to Voice settings.' );
		}
	}

	abstract public function generate_audio( $text, $post_id );

	protected function save_audio_file( $audio_data, $post_id ) {
		global $wp_filesystem;

		require_once( ABSPATH . 'wp-admin/includes/file.php' );
		WP_Filesystem();

		$upload_dir = wp_upload_dir();
		$ptv_dir = $upload_dir['basedir'] . '/post-to-voice';

		if ( ! $wp_filesystem->is_dir( $ptv_dir ) ) {
			$wp_filesystem->mkdir( $ptv_dir );
		}

		$filename = 'post-' . $post_id . '-audio-' . time() . '.mp3';
		$filepath = $ptv_dir . '/' . $filename;

		if ( ! $wp_filesystem->put_contents( $filepath, $audio_data, FS_CHMOD_FILE ) ) {
			throw new Exception( 'Could not write audio file' );
		}

		// Register as WordPress attachment
		$file_url = $upload_dir['baseurl'] . '/post-to-voice/' . $filename;
		$attachment_id = $this->register_attachment( $filepath, $file_url, $post_id );

		// Return both URL and attachment ID
		return array(
			'url'             => $file_url,
			'attachment_id'   => $attachment_id,
		);
	}

	protected function register_attachment( $filepath, $file_url, $post_id ) {
		require_once( ABSPATH . 'wp-admin/includes/image.php' );

		$attachment = array(
			'post_mime_type' => 'audio/mpeg',
			'post_title'     => 'Audio: ' . get_the_title( $post_id ),
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_parent'    => $post_id,
		);

		$attachment_id = wp_insert_attachment( $attachment, $filepath, $post_id );

		if ( ! is_wp_error( $attachment_id ) ) {
			$attach_data = wp_generate_attachment_metadata( $attachment_id, $filepath );
			wp_update_attachment_metadata( $attachment_id, $attach_data );
		}

		return $attachment_id;
	}
}
