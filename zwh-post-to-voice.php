<?php
/**
 * Plugin Name: ZWH Post to Voice
 * Plugin URI: https://github.com/ziv-was-here/zwh-post-to-voice
 * Description: Convert post text to audio and embed a player
 * Version: 1.0.0
 * Author: Ziv Rozenberg
 * Author URI: https://zivwashere.com/
 * License: GPL-2.0-or-later
 * Text Domain: zwh-post-to-voice
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

// Define plugin constants
define( 'POST_TO_VOICE_DIR', plugin_dir_path( __FILE__ ) );
define( 'POST_TO_VOICE_URL', plugin_dir_url( __FILE__ ) );
define( 'POST_TO_VOICE_VERSION', '1.0.0' );

// Autoload classes
require_once POST_TO_VOICE_DIR . 'class-tts-service.php';
require_once POST_TO_VOICE_DIR . 'class-google-tts.php';

class Post_to_Voice {
	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_scripts' ) );
		add_filter( 'the_content', array( $this, 'add_audio_player' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_audio_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_audio_meta' ), 10, 2 );

		// AJAX handlers
		add_action( 'wp_ajax_ptv_generate_audio', array( $this, 'ajax_generate_audio' ) );
		add_action( 'wp_ajax_ptv_remove_audio', array( $this, 'ajax_remove_audio' ) );
	}

	public function register_admin_menu() {
		add_menu_page(
			__( 'Post to Voice', 'post-to-voice' ),
			__( 'Post to Voice', 'post-to-voice' ),
			'manage_options',
			'post-to-voice',
			array( $this, 'render_settings_page' ),
			'dashicons-controls-volumeon'
		);
	}

	/**
	 * Enqueue the Weathered Chronicle admin styles (settings page + post editor).
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_admin_styles( $hook ) {
		if ( ! in_array( $hook, array( 'toplevel_page_post-to-voice', 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		wp_enqueue_style(
			'post-to-voice-font',
			'https://fonts.googleapis.com/css2?family=Epilogue:ital,wght@0,400;0,600;0,700;1,700;1,800&display=swap',
			array(),
			null // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		);
		wp_enqueue_style(
			'post-to-voice-admin',
			POST_TO_VOICE_URL . 'admin.css',
			array(),
			POST_TO_VOICE_VERSION
		);
	}

	public function register_settings() {
		register_setting( 'post-to-voice', 'post_to_voice_tts_service', array(
			'sanitize_callback' => 'sanitize_text_field',
		) );
		register_setting( 'post-to-voice', 'post_to_voice_api_key', array(
			'sanitize_callback' => 'sanitize_text_field',
		) );
		register_setting( 'post-to-voice', 'post_to_voice_voice_name', array(
			'sanitize_callback' => 'sanitize_text_field',
		) );
		register_setting( 'post-to-voice', 'post_to_voice_language', array(
			'sanitize_callback' => 'sanitize_text_field',
		) );
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tts_service = get_option( 'post_to_voice_tts_service', 'google' );
		$api_key = get_option( 'post_to_voice_api_key', '' );
		$voice_name = get_option( 'post_to_voice_voice_name', 'en-US-Neural2-A' );
		$language = get_option( 'post_to_voice_language', 'en-US' );
		?>
		<div class="wrap ptv-wrap">
			<header class="ptv-product-header">
				<div class="ptv-product-brand">
					<span class="ptv-product-mark" aria-hidden="true">
						<span class="dashicons dashicons-controls-volumeon"></span>
					</span>
					<div>
						<h1 class="ptv-product-title"><?php esc_html_e( 'Post to Voice', 'post-to-voice' ); ?></h1>
						<p class="ptv-product-tagline"><?php esc_html_e( 'Turn every article into audio', 'post-to-voice' ); ?></p>
					</div>
				</div>
				<div class="ptv-product-meta">
					<span class="ptv-version-badge">v<?php echo esc_html( POST_TO_VOICE_VERSION ); ?></span>
					<a class="ptv-brand-link" href="https://zivwashere.com" target="_blank" rel="noopener">zivwashere.com</a>
				</div>
			</header>

			<div class="ptv-caption-box">
				<?php esc_html_e( 'Tip: after saving your API key, open any post and hit "Generate Audio" in the Audio Version box.', 'post-to-voice' ); ?>
			</div>

			<div class="ptv-layout">

				<!-- Main form -->
				<div class="ptv-form-col">
					<form action="options.php" method="post">
						<?php settings_fields( 'post-to-voice' ); ?>

						<div class="ptv-provider-select-wrap">
							<label for="post_to_voice_tts_service"><?php esc_html_e( 'TTS Service', 'post-to-voice' ); ?></label>
							<select name="post_to_voice_tts_service" id="post_to_voice_tts_service">
								<option value="google" <?php selected( $tts_service, 'google' ); ?>>Google Cloud TTS</option>
								<option value="amazon" <?php selected( $tts_service, 'amazon' ); ?>>Amazon Polly (coming soon)</option>
								<option value="elevenlabs" <?php selected( $tts_service, 'elevenlabs' ); ?>>ElevenLabs (coming soon)</option>
							</select>
						</div>

						<!-- Google Cloud TTS -->
						<div class="ptv-card ptv-provider-group <?php echo 'google' === $tts_service ? 'ptv-active' : ''; ?>" data-provider="google">
							<h2><?php esc_html_e( 'Google Cloud TTS settings', 'post-to-voice' ); ?></h2>
							<table class="form-table">
								<tr>
									<th><label for="post_to_voice_api_key"><?php esc_html_e( 'API Key', 'post-to-voice' ); ?></label></th>
									<td>
										<input type="password" name="post_to_voice_api_key" id="post_to_voice_api_key" value="<?php echo esc_attr( $api_key ); ?>" class="regular-text" autocomplete="new-password" />
										<p class="description"><?php esc_html_e( 'Enter your Google Cloud API key', 'post-to-voice' ); ?></p>
									</td>
								</tr>
								<tr>
									<th><label for="post_to_voice_voice_name"><?php esc_html_e( 'Voice', 'post-to-voice' ); ?></label></th>
									<td>
										<select name="post_to_voice_voice_name" id="post_to_voice_voice_name">
											<option value="en-US-Neural2-A" <?php selected( $voice_name, 'en-US-Neural2-A' ); ?>>American English - Neural A</option>
											<option value="en-US-Neural2-C" <?php selected( $voice_name, 'en-US-Neural2-C' ); ?>>American English - Neural C</option>
											<option value="en-GB-Neural2-A" <?php selected( $voice_name, 'en-GB-Neural2-A' ); ?>>British English - Neural A</option>
											<option value="en-AU-Neural2-A" <?php selected( $voice_name, 'en-AU-Neural2-A' ); ?>>Australian English - Neural A</option>
										</select>
									</td>
								</tr>
								<tr>
									<th><label for="post_to_voice_language"><?php esc_html_e( 'Language', 'post-to-voice' ); ?></label></th>
									<td>
										<input type="text" name="post_to_voice_language" id="post_to_voice_language" value="<?php echo esc_attr( $language ); ?>" class="regular-text" />
										<p class="description"><?php esc_html_e( 'e.g., en-US, es-ES, fr-FR', 'post-to-voice' ); ?></p>
									</td>
								</tr>
							</table>
						</div>

						<!-- Amazon Polly -->
						<div class="ptv-card ptv-provider-group <?php echo 'amazon' === $tts_service ? 'ptv-active' : ''; ?>" data-provider="amazon">
							<h2><?php esc_html_e( 'Amazon Polly settings', 'post-to-voice' ); ?></h2>
							<p class="ptv-coming-soon"><?php esc_html_e( 'Coming soon! Amazon Polly support is on the roadmap. Pick Google Cloud TTS in the meantime.', 'post-to-voice' ); ?></p>
						</div>

						<!-- ElevenLabs -->
						<div class="ptv-card ptv-provider-group <?php echo 'elevenlabs' === $tts_service ? 'ptv-active' : ''; ?>" data-provider="elevenlabs">
							<h2><?php esc_html_e( 'ElevenLabs settings', 'post-to-voice' ); ?></h2>
							<p class="ptv-coming-soon"><?php esc_html_e( 'Coming soon! ElevenLabs voices are on the roadmap. Pick Google Cloud TTS in the meantime.', 'post-to-voice' ); ?></p>
						</div>

						<?php submit_button(); ?>
					</form>
				</div><!-- /.ptv-form-col -->

				<!-- Sidebar -->
				<div class="ptv-sidebar-col">
					<div class="ptv-sidebar-box">
						<h3><?php esc_html_e( 'Saved API keys', 'post-to-voice' ); ?></h3>
						<ul class="ptv-provider-status">
							<li>
								<span class="ptv-status-dot <?php echo $api_key ? 'has-key' : 'no-key'; ?>"></span>
								<span class="ptv-provider-name">Google Cloud TTS<span>Neural2 voices</span></span>
								<?php if ( 'google' === $tts_service ) : ?>
									<span class="ptv-active-badge"><?php esc_html_e( 'Active', 'post-to-voice' ); ?></span>
								<?php else : ?>
									<span class="ptv-key-badge <?php echo $api_key ? 'saved' : 'missing'; ?>">
										<?php echo $api_key ? esc_html__( 'Saved', 'post-to-voice' ) : esc_html__( 'No key', 'post-to-voice' ); ?>
									</span>
								<?php endif; ?>
							</li>
							<li>
								<span class="ptv-status-dot no-key"></span>
								<span class="ptv-provider-name">Amazon Polly<span>AWS</span></span>
								<span class="ptv-key-badge soon"><?php esc_html_e( 'Soon', 'post-to-voice' ); ?></span>
							</li>
							<li>
								<span class="ptv-status-dot no-key"></span>
								<span class="ptv-provider-name">ElevenLabs<span>Premium voices</span></span>
								<span class="ptv-key-badge soon"><?php esc_html_e( 'Soon', 'post-to-voice' ); ?></span>
							</li>
						</ul>
					</div>
				</div><!-- /.ptv-sidebar-col -->

			</div><!-- /.ptv-layout -->

			<script>
			(function () {
				var dropdown = document.getElementById( 'post_to_voice_tts_service' );
				if ( ! dropdown ) {
					return;
				}
				function showProvider( val ) {
					document.querySelectorAll( '.ptv-provider-group' ).forEach( function ( el ) {
						el.classList.toggle( 'ptv-active', el.dataset.provider === val );
					} );
				}
				dropdown.addEventListener( 'change', function () { showProvider( this.value ); } );
				showProvider( dropdown.value );
			}());
			</script>
		</div>
		<?php
	}

	public function enqueue_frontend_scripts() {
		wp_enqueue_style(
			'post-to-voice-frontend',
			POST_TO_VOICE_URL . 'frontend.css',
			array(),
			POST_TO_VOICE_VERSION
		);

		wp_enqueue_script(
			'post-to-voice-player',
			POST_TO_VOICE_URL . 'player.js',
			array(),
			POST_TO_VOICE_VERSION,
			true
		);
	}

	public function add_audio_meta_box() {
		add_meta_box(
			'post_to_voice_meta',
			__( 'Audio Version', 'post-to-voice' ),
			array( $this, 'render_meta_box' ),
			'post',
			'side',
			'high'
		);
	}

	public function render_meta_box( $post ) {
		$audio_url = get_post_meta( $post->ID, '_post_to_voice_audio_url', true );
		$enable_audio = get_post_meta( $post->ID, '_post_to_voice_enabled', true );

		wp_nonce_field( 'ptv_meta_nonce', 'ptv_meta_nonce' );
		?>
		<div id="ptv-meta-box">
			<p class="ptv-enable-row">
				<label>
					<input type="checkbox" name="ptv_enable_audio" value="1" <?php checked( $enable_audio, 1 ); ?> />
					<?php esc_html_e( 'Enable audio for this post', 'post-to-voice' ); ?>
				</label>
			</p>

			<?php if ( $audio_url ) : ?>
				<p>
					<span class="ptv-audio-label"><?php esc_html_e( 'Audio Attached', 'post-to-voice' ); ?></span><br />
					<audio controls style="width:100%; margin:10px 0;">
						<source src="<?php echo esc_url( $audio_url ); ?>" type="audio/mpeg">
					</audio>
					<button type="button" class="button button-small ptv-remove-audio" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
						<?php esc_html_e( 'Remove Audio', 'post-to-voice' ); ?>
					</button>
				</p>
			<?php else : ?>
				<p>
					<button type="button" class="button button-primary ptv-generate-audio" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
						<?php esc_html_e( 'Generate Audio', 'post-to-voice' ); ?>
					</button>
					<span class="ptv-loading" style="display:none;">
						<span class="spinner is-active"></span> Generating...
					</span>
				</p>
			<?php endif; ?>
		</div>

		<script>
		jQuery(document).ready(function($) {
			$(document).on('click', '.ptv-generate-audio', function(e) {
				e.preventDefault();
				var postId = $(this).data('post-id');
				var $btn = $(this);
				var $loading = $btn.closest('.ptv-meta-box').find('.ptv-loading');

				$btn.hide();
				$loading.show();

				$.post(ajaxurl, {
					action: 'ptv_generate_audio',
					post_id: postId,
					nonce: $('input[name="ptv_meta_nonce"]').val()
				}, function(response) {
					if (response.success) {
						location.reload();
					} else {
						alert('Error: ' + response.data);
						$btn.show();
						$loading.hide();
					}
				});
			});

			$(document).on('click', '.ptv-remove-audio', function(e) {
				e.preventDefault();
				if (confirm('Remove audio from this post?')) {
					var postId = $(this).data('post-id');
					$.post(ajaxurl, {
						action: 'ptv_remove_audio',
						post_id: postId,
						nonce: $('input[name="ptv_meta_nonce"]').val()
					}, function(response) {
						if (response.success) {
							location.reload();
						}
					});
				}
			});
		});
		</script>
		<?php
	}

	public function save_audio_meta( $post_id, $post ) {
		if ( ! isset( $_POST['ptv_meta_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ptv_meta_nonce'] ) ), 'ptv_meta_nonce' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$enable = isset( $_POST['ptv_enable_audio'] ) ? 1 : 0;
		update_post_meta( $post_id, '_post_to_voice_enabled', $enable );
	}

	public function ajax_generate_audio() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'ptv_meta_nonce' ) ) {
			wp_send_json_error( 'Security check failed' );
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Permission denied' );
		}

		if ( ! isset( $_POST['post_id'] ) ) {
			wp_send_json_error( 'Missing post ID' );
		}

		$post_id = intval( $_POST['post_id'] );
		$post = get_post( $post_id );

		if ( ! $post ) {
			wp_send_json_error( 'Post not found' );
		}

		// Get the text to convert
		$text = wp_strip_all_tags( $post->post_content );
		$text = preg_replace( '/\s+/', ' ', $text ); // Normalize whitespace
		$text = substr( $text, 0, 4000 ); // Limit to 4000 chars for API

		// Generate audio using TTS service
		$tts_service = get_option( 'post_to_voice_tts_service', 'google' );

		try {
			if ( 'google' === $tts_service ) {
				$tts = new Google_TTS();
				$result = $tts->generate_audio( $text, $post_id );

				// Handle both array and string returns for backwards compatibility
				$audio_url = is_array( $result ) ? $result['url'] : $result;
				$attachment_id = is_array( $result ) ? $result['attachment_id'] : null;

				update_post_meta( $post_id, '_post_to_voice_audio_url', $audio_url );
				if ( $attachment_id ) {
					update_post_meta( $post_id, '_post_to_voice_attachment_id', $attachment_id );
				}
				update_post_meta( $post_id, '_post_to_voice_enabled', 1 );

				wp_send_json_success( array( 'audio_url' => $audio_url ) );
			} else {
				wp_send_json_error( 'Service not yet implemented' );
			}
		} catch ( Exception $e ) {
			wp_send_json_error( $e->getMessage() );
		}
	}

	public function ajax_remove_audio() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'ptv_meta_nonce' ) ) {
			wp_send_json_error( 'Security check failed' );
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Permission denied' );
		}

		if ( ! isset( $_POST['post_id'] ) ) {
			wp_send_json_error( 'Missing post ID' );
		}

		$post_id = intval( $_POST['post_id'] );

		// Delete the attachment
		$attachment_id = get_post_meta( $post_id, '_post_to_voice_attachment_id', true );
		if ( $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
			delete_post_meta( $post_id, '_post_to_voice_attachment_id' );
		}

		delete_post_meta( $post_id, '_post_to_voice_audio_url' );
		update_post_meta( $post_id, '_post_to_voice_enabled', 0 );

		wp_send_json_success();
	}

	public function add_audio_player( $content ) {
		if ( ! is_singular( 'post' ) ) {
			return $content;
		}

		$post_id = get_the_ID();
		$enabled = get_post_meta( $post_id, '_post_to_voice_enabled', true );
		$audio_url = get_post_meta( $post_id, '_post_to_voice_audio_url', true );

		if ( ! $enabled || ! $audio_url ) {
			return $content;
		}

		$post = get_post( $post_id );
		$post_title = esc_html( $post->post_title );
		$post_url = esc_url( get_permalink( $post_id ) );
		$safe_audio_url = esc_url( $audio_url );

		// Build structured data
		$schema = array(
			'@context' => 'https://schema.org',
			'@type' => 'AudioObject',
			'name' => 'Audio narration for: ' . $post_title,
			'description' => 'Audio version of this article.',
			'contentUrl' => $safe_audio_url,
			'encodingFormat' => 'audio/mpeg',
			'isBasedOn' => array(
				'@type' => 'BlogPosting',
				'headline' => $post_title,
				'url' => $post_url,
			),
		);

		$schema_json = wp_json_encode( $schema );

		$player_html = sprintf(
			'<div class="ptv-player-container">
				<div class="ptv-player-header">
					<svg class="ptv-player-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
						<polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"></polygon>
						<path d="M15.54 8.46a6.5 6.5 0 0 1 0 9.07"></path>
						<path d="M19.07 4.93a10.5 10.5 0 0 1 0 14.14"></path>
					</svg>
					<span>%s</span>
				</div>
				<audio controls class="ptv-audio-player" preload="metadata">
					<source src="%s" type="audio/mpeg">
					%s
				</audio>
			</div>
			<script type="application/ld+json">%s</script>',
			esc_html__( 'Listen to this article', 'post-to-voice' ),
			$safe_audio_url,
			esc_html__( 'Your browser does not support the audio element.', 'post-to-voice' ),
			$schema_json
		);

		return $player_html . $content;
	}
}

// Initialize plugin
add_action( 'plugins_loaded', function() {
	Post_to_Voice::get_instance();
} );

// Activation hook
register_activation_hook( __FILE__, function() {
	// Create uploads directory if needed
	wp_mkdir_p( wp_upload_dir()['basedir'] . '/post-to-voice' );
} );
