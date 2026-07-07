<?php
/**
 * Base TTS Service class
 */

defined( 'ABSPATH' ) || exit;

abstract class TTS_Service {

	/**
	 * Generate audio for the given text.
	 *
	 * @param string $text    Plain text to synthesize.
	 * @param int    $post_id Post the audio belongs to.
	 * @return array { url: string, attachment_id: int }
	 * @throws Exception On configuration or API errors.
	 */
	abstract public function generate_audio( $text, $post_id );

	/**
	 * Require an option to be set; throws a friendly error naming the setting.
	 *
	 * @param string $option Option name.
	 * @param string $label  Human label used in the error message.
	 * @return string Option value.
	 * @throws Exception When empty.
	 */
	protected function require_option( $option, $label ) {
		$value = get_option( $option, '' );
		if ( '' === trim( (string) $value ) ) {
			throw new Exception( esc_html( $label . ' not configured. Please set it in Post to Voice settings.' ) );
		}
		return $value;
	}

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
