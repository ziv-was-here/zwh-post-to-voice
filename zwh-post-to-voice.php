<?php
/**
 * Plugin Name: ZWH Post to Voice
 * Update URI:  https://github.com/ziv-was-here/zwh-post-to-voice
 * Plugin URI: https://github.com/ziv-was-here/zwh-post-to-voice
 * Description: Convert post text to audio and embed a player
 * Version: 1.2.1
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
define( 'POST_TO_VOICE_VERSION', '1.2.1' );

// Autoload classes
require_once POST_TO_VOICE_DIR . 'class-tts-service.php';
require_once POST_TO_VOICE_DIR . 'class-google-tts.php';
require_once POST_TO_VOICE_DIR . 'class-amazon-polly-tts.php';
require_once POST_TO_VOICE_DIR . 'class-elevenlabs-tts.php';

class Post_to_Voice {
	private static $instance = null;

	/** Default player style values. */
	const STYLE_DEFAULTS = array(
		'accent'   => '#667eea',
		'accent_2' => '#764ba2',
		'text'     => '#ffffff',
		'radius'   => 12,
	);

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
		add_shortcode( 'post_to_voice', array( $this, 'render_shortcode' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_audio_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_audio_meta' ), 10, 2 );

		// AJAX handlers
		add_action( 'wp_ajax_ptv_generate_audio', array( $this, 'ajax_generate_audio' ) );
		add_action( 'wp_ajax_ptv_remove_audio', array( $this, 'ajax_remove_audio' ) );
	}

	public function register_admin_menu() {
		add_menu_page(
			__( 'Post to Voice', 'zwh-post-to-voice' ),
			__( 'Post to Voice', 'zwh-post-to-voice' ),
			'manage_options',
			'post-to-voice',
			array( $this, 'render_settings_page' ),
			'dashicons-controls-volumeon'
		);
	}

	/**
	 * Enqueue the admin styles (settings page + post editor).
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
			POST_TO_VOICE_VERSION
		);
		wp_enqueue_style(
			'post-to-voice-admin',
			POST_TO_VOICE_URL . 'admin.css',
			array(),
			POST_TO_VOICE_VERSION
		);
	}

	public function register_settings() {
		$text_settings = array(
			// General
			'post_to_voice_tts_service',
			// Google Cloud TTS
			'post_to_voice_api_key',
			'post_to_voice_voice_name',
			'post_to_voice_language',
			// Amazon Polly
			'post_to_voice_aws_access_key',
			'post_to_voice_aws_secret_key',
			'post_to_voice_aws_region',
			'post_to_voice_aws_voice',
			'post_to_voice_aws_engine',
			// ElevenLabs
			'post_to_voice_el_api_key',
			'post_to_voice_el_voice_id',
			'post_to_voice_el_model',
			// Player placement
			'post_to_voice_placement',
		);
		foreach ( $text_settings as $setting ) {
			register_setting( 'post-to-voice', $setting, array(
				'sanitize_callback' => 'sanitize_text_field',
			) );
		}

		// Player style settings
		register_setting( 'post-to-voice', 'post_to_voice_accent_color', array(
			'sanitize_callback' => 'sanitize_hex_color',
		) );
		register_setting( 'post-to-voice', 'post_to_voice_accent_color_2', array(
			'sanitize_callback' => 'sanitize_hex_color',
		) );
		register_setting( 'post-to-voice', 'post_to_voice_text_color', array(
			'sanitize_callback' => 'sanitize_hex_color',
		) );
		register_setting( 'post-to-voice', 'post_to_voice_radius', array(
			'sanitize_callback' => 'absint',
		) );
		register_setting( 'post-to-voice', 'post_to_voice_custom_css', array(
			'sanitize_callback' => array( $this, 'sanitize_custom_css' ),
		) );
	}

	/**
	 * Sanitize the custom CSS textarea: strip tags and anything that could
	 * close the style element.
	 *
	 * @param string $css Raw input.
	 * @return string
	 */
	public function sanitize_custom_css( $css ) {
		$css = wp_strip_all_tags( (string) $css );
		$css = str_ireplace( array( '</style', '<style', 'expression(', 'javascript:' ), '', $css );
		return $css;
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tts_service = get_option( 'post_to_voice_tts_service', 'google' );
		$api_key     = get_option( 'post_to_voice_api_key', '' );
		$voice_name  = get_option( 'post_to_voice_voice_name', 'en-US-Neural2-A' );
		$language    = get_option( 'post_to_voice_language', 'en-US' );

		$aws_access_key = get_option( 'post_to_voice_aws_access_key', '' );
		$aws_secret_key = get_option( 'post_to_voice_aws_secret_key', '' );
		$aws_region     = get_option( 'post_to_voice_aws_region', 'us-east-1' );
		$aws_voice      = get_option( 'post_to_voice_aws_voice', 'Joanna' );
		$aws_engine     = get_option( 'post_to_voice_aws_engine', 'neural' );

		$el_api_key  = get_option( 'post_to_voice_el_api_key', '' );
		$el_voice_id = get_option( 'post_to_voice_el_voice_id', '21m00Tcm4TlvDq8ikWAM' );
		$el_model    = get_option( 'post_to_voice_el_model', 'eleven_multilingual_v2' );

		$accent     = get_option( 'post_to_voice_accent_color', self::STYLE_DEFAULTS['accent'] );
		$accent2    = get_option( 'post_to_voice_accent_color_2', self::STYLE_DEFAULTS['accent_2'] );
		$text_color = get_option( 'post_to_voice_text_color', self::STYLE_DEFAULTS['text'] );
		$radius     = get_option( 'post_to_voice_radius', self::STYLE_DEFAULTS['radius'] );
		$custom_css = get_option( 'post_to_voice_custom_css', '' );
		$placement  = get_option( 'post_to_voice_placement', 'before' );
		?>
		<div class="wrap ptv-wrap">
			<header class="ptv-product-header">
				<div class="ptv-product-brand">
					<span class="ptv-product-mark" aria-hidden="true">
						<span class="dashicons dashicons-controls-volumeon"></span>
					</span>
					<div>
						<h1 class="ptv-product-title"><?php esc_html_e( 'Post to Voice', 'zwh-post-to-voice' ); ?></h1>
						<p class="ptv-product-tagline"><?php esc_html_e( 'Turn every article into audio', 'zwh-post-to-voice' ); ?></p>
					</div>
				</div>
				<div class="ptv-product-meta">
					<span class="ptv-version-badge">v<?php echo esc_html( POST_TO_VOICE_VERSION ); ?></span>
					<a class="ptv-brand-link" href="https://zivwashere.com" target="_blank" rel="noopener">zivwashere.com</a>
				</div>
			</header>

			<div class="ptv-caption-box">
				<?php esc_html_e( 'Tip: after saving your API key, open any post and hit "Generate Audio" in the Audio Version box.', 'zwh-post-to-voice' ); ?>
			</div>

			<div class="ptv-layout">

				<!-- Main form -->
				<div class="ptv-form-col">
					<form action="options.php" method="post">
						<?php settings_fields( 'post-to-voice' ); ?>

						<div class="ptv-provider-select-wrap">
							<label for="post_to_voice_tts_service"><?php esc_html_e( 'TTS Service', 'zwh-post-to-voice' ); ?></label>
							<select name="post_to_voice_tts_service" id="post_to_voice_tts_service">
								<option value="google" <?php selected( $tts_service, 'google' ); ?>>Google Cloud TTS</option>
								<option value="amazon" <?php selected( $tts_service, 'amazon' ); ?>>Amazon Polly</option>
								<option value="elevenlabs" <?php selected( $tts_service, 'elevenlabs' ); ?>>ElevenLabs</option>
							</select>
						</div>

						<!-- Google Cloud TTS -->
						<div class="ptv-card ptv-provider-group <?php echo 'google' === $tts_service ? 'ptv-active' : ''; ?>" data-provider="google">
							<h2><?php esc_html_e( 'Google Cloud TTS settings', 'zwh-post-to-voice' ); ?></h2>
							<table class="form-table">
								<tr>
									<th><label for="post_to_voice_api_key"><?php esc_html_e( 'API Key', 'zwh-post-to-voice' ); ?></label></th>
									<td>
										<input type="password" name="post_to_voice_api_key" id="post_to_voice_api_key" value="<?php echo esc_attr( $api_key ); ?>" class="regular-text" autocomplete="new-password" />
										<p class="description"><?php esc_html_e( 'Enter your Google Cloud API key', 'zwh-post-to-voice' ); ?></p>
									</td>
								</tr>
								<tr>
									<th><label for="post_to_voice_voice_name"><?php esc_html_e( 'Voice', 'zwh-post-to-voice' ); ?></label></th>
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
									<th><label for="post_to_voice_language"><?php esc_html_e( 'Language', 'zwh-post-to-voice' ); ?></label></th>
									<td>
										<input type="text" name="post_to_voice_language" id="post_to_voice_language" value="<?php echo esc_attr( $language ); ?>" class="regular-text" />
										<p class="description"><?php esc_html_e( 'e.g., en-US, es-ES, fr-FR', 'zwh-post-to-voice' ); ?></p>
									</td>
								</tr>
							</table>
						</div>

						<!-- Amazon Polly -->
						<div class="ptv-card ptv-provider-group <?php echo 'amazon' === $tts_service ? 'ptv-active' : ''; ?>" data-provider="amazon">
							<h2><?php esc_html_e( 'Amazon Polly settings', 'zwh-post-to-voice' ); ?></h2>
							<table class="form-table">
								<tr>
									<th><label for="post_to_voice_aws_access_key"><?php esc_html_e( 'Access Key ID', 'zwh-post-to-voice' ); ?></label></th>
									<td>
										<input type="text" name="post_to_voice_aws_access_key" id="post_to_voice_aws_access_key" value="<?php echo esc_attr( $aws_access_key ); ?>" class="regular-text" autocomplete="off" />
										<p class="description"><?php esc_html_e( 'IAM user needs the polly:SynthesizeSpeech permission.', 'zwh-post-to-voice' ); ?></p>
									</td>
								</tr>
								<tr>
									<th><label for="post_to_voice_aws_secret_key"><?php esc_html_e( 'Secret Access Key', 'zwh-post-to-voice' ); ?></label></th>
									<td>
										<input type="password" name="post_to_voice_aws_secret_key" id="post_to_voice_aws_secret_key" value="<?php echo esc_attr( $aws_secret_key ); ?>" class="regular-text" autocomplete="new-password" />
									</td>
								</tr>
								<tr>
									<th><label for="post_to_voice_aws_region"><?php esc_html_e( 'Region', 'zwh-post-to-voice' ); ?></label></th>
									<td>
										<input type="text" name="post_to_voice_aws_region" id="post_to_voice_aws_region" value="<?php echo esc_attr( $aws_region ); ?>" class="regular-text" />
										<p class="description"><?php esc_html_e( 'e.g., us-east-1, eu-west-1', 'zwh-post-to-voice' ); ?></p>
									</td>
								</tr>
								<tr>
									<th><label for="post_to_voice_aws_voice"><?php esc_html_e( 'Voice', 'zwh-post-to-voice' ); ?></label></th>
									<td>
										<select name="post_to_voice_aws_voice" id="post_to_voice_aws_voice">
											<?php
											$polly_voices = array( 'Joanna', 'Matthew', 'Ruth', 'Stephen', 'Danielle', 'Gregory', 'Amy', 'Brian', 'Emma', 'Arthur', 'Olivia', 'Kajal' );
											foreach ( $polly_voices as $pv ) :
												?>
												<option value="<?php echo esc_attr( $pv ); ?>" <?php selected( $aws_voice, $pv ); ?>><?php echo esc_html( $pv ); ?></option>
											<?php endforeach; ?>
										</select>
									</td>
								</tr>
								<tr>
									<th><label for="post_to_voice_aws_engine"><?php esc_html_e( 'Engine', 'zwh-post-to-voice' ); ?></label></th>
									<td>
										<select name="post_to_voice_aws_engine" id="post_to_voice_aws_engine">
											<option value="neural" <?php selected( $aws_engine, 'neural' ); ?>><?php esc_html_e( 'Neural (higher quality)', 'zwh-post-to-voice' ); ?></option>
											<option value="standard" <?php selected( $aws_engine, 'standard' ); ?>><?php esc_html_e( 'Standard (cheaper)', 'zwh-post-to-voice' ); ?></option>
										</select>
									</td>
								</tr>
							</table>
						</div>

						<!-- ElevenLabs -->
						<div class="ptv-card ptv-provider-group <?php echo 'elevenlabs' === $tts_service ? 'ptv-active' : ''; ?>" data-provider="elevenlabs">
							<h2><?php esc_html_e( 'ElevenLabs settings', 'zwh-post-to-voice' ); ?></h2>
							<table class="form-table">
								<tr>
									<th><label for="post_to_voice_el_api_key"><?php esc_html_e( 'API Key', 'zwh-post-to-voice' ); ?></label></th>
									<td>
										<input type="password" name="post_to_voice_el_api_key" id="post_to_voice_el_api_key" value="<?php echo esc_attr( $el_api_key ); ?>" class="regular-text" autocomplete="new-password" />
										<p class="description"><?php esc_html_e( 'Found under Profile → API Keys on elevenlabs.io', 'zwh-post-to-voice' ); ?></p>
									</td>
								</tr>
								<tr>
									<th><label for="post_to_voice_el_voice_id"><?php esc_html_e( 'Voice ID', 'zwh-post-to-voice' ); ?></label></th>
									<td>
										<input type="text" name="post_to_voice_el_voice_id" id="post_to_voice_el_voice_id" value="<?php echo esc_attr( $el_voice_id ); ?>" class="regular-text" />
										<p class="description"><?php esc_html_e( 'Copy from the Voices page. Default is "Rachel".', 'zwh-post-to-voice' ); ?></p>
									</td>
								</tr>
								<tr>
									<th><label for="post_to_voice_el_model"><?php esc_html_e( 'Model', 'zwh-post-to-voice' ); ?></label></th>
									<td>
										<select name="post_to_voice_el_model" id="post_to_voice_el_model">
											<option value="eleven_multilingual_v2" <?php selected( $el_model, 'eleven_multilingual_v2' ); ?>>Multilingual v2</option>
											<option value="eleven_turbo_v2_5" <?php selected( $el_model, 'eleven_turbo_v2_5' ); ?>>Turbo v2.5 (faster, cheaper)</option>
											<option value="eleven_monolingual_v1" <?php selected( $el_model, 'eleven_monolingual_v1' ); ?>>Monolingual v1 (English)</option>
										</select>
									</td>
								</tr>
							</table>
						</div>

						<!-- Player -->
						<div class="ptv-card">
							<h2><?php esc_html_e( 'Player', 'zwh-post-to-voice' ); ?></h2>
							<table class="form-table">
								<tr>
									<th><label for="post_to_voice_placement"><?php esc_html_e( 'Placement', 'zwh-post-to-voice' ); ?></label></th>
									<td>
										<select name="post_to_voice_placement" id="post_to_voice_placement">
											<option value="before" <?php selected( $placement, 'before' ); ?>><?php esc_html_e( 'Before content (default)', 'zwh-post-to-voice' ); ?></option>
											<option value="after" <?php selected( $placement, 'after' ); ?>><?php esc_html_e( 'After content', 'zwh-post-to-voice' ); ?></option>
											<option value="shortcode" <?php selected( $placement, 'shortcode' ); ?>><?php esc_html_e( 'Shortcode only', 'zwh-post-to-voice' ); ?></option>
										</select>
										<p class="description">
											<?php esc_html_e( 'Use the [post_to_voice] shortcode to place the player anywhere. If a post contains the shortcode, the automatic player is skipped for that post.', 'zwh-post-to-voice' ); ?>
										</p>
									</td>
								</tr>
								<tr>
									<th><label for="post_to_voice_accent_color"><?php esc_html_e( 'Accent color', 'zwh-post-to-voice' ); ?></label></th>
									<td>
										<input type="color" name="post_to_voice_accent_color" id="post_to_voice_accent_color" value="<?php echo esc_attr( $accent ); ?>" />
										<p class="description"><?php esc_html_e( 'Gradient start / play icon color.', 'zwh-post-to-voice' ); ?></p>
									</td>
								</tr>
								<tr>
									<th><label for="post_to_voice_accent_color_2"><?php esc_html_e( 'Accent color 2', 'zwh-post-to-voice' ); ?></label></th>
									<td>
										<input type="color" name="post_to_voice_accent_color_2" id="post_to_voice_accent_color_2" value="<?php echo esc_attr( $accent2 ); ?>" />
										<p class="description"><?php esc_html_e( 'Gradient end color.', 'zwh-post-to-voice' ); ?></p>
									</td>
								</tr>
								<tr>
									<th><label for="post_to_voice_text_color"><?php esc_html_e( 'Text color', 'zwh-post-to-voice' ); ?></label></th>
									<td>
										<input type="color" name="post_to_voice_text_color" id="post_to_voice_text_color" value="<?php echo esc_attr( $text_color ); ?>" />
									</td>
								</tr>
								<tr>
									<th><label for="post_to_voice_radius"><?php esc_html_e( 'Corner radius (px)', 'zwh-post-to-voice' ); ?></label></th>
									<td>
										<input type="number" min="0" max="60" name="post_to_voice_radius" id="post_to_voice_radius" value="<?php echo esc_attr( $radius ); ?>" class="small-text" />
									</td>
								</tr>
								<tr>
									<th><label for="post_to_voice_custom_css"><?php esc_html_e( 'Custom CSS', 'zwh-post-to-voice' ); ?></label></th>
									<td>
										<textarea name="post_to_voice_custom_css" id="post_to_voice_custom_css" rows="8" class="large-text code" placeholder=".ptv-player-container { }"><?php echo esc_textarea( $custom_css ); ?></textarea>
										<p class="description">
											<?php esc_html_e( 'Loaded after the default styles — override anything. Useful selectors: .ptv-player-container, .ptv-player-header, .ptv-play-btn, .ptv-seek, .ptv-time, .ptv-speed-btn', 'zwh-post-to-voice' ); ?>
										</p>
									</td>
								</tr>
							</table>
						</div>

						<?php submit_button(); ?>
					</form>
				</div><!-- /.ptv-form-col -->

				<!-- Sidebar -->
				<div class="ptv-sidebar-col">
					<div class="ptv-sidebar-box">
						<h3><?php esc_html_e( 'Saved API keys', 'zwh-post-to-voice' ); ?></h3>
						<ul class="ptv-provider-status">
							<li>
								<span class="ptv-status-dot <?php echo $api_key ? 'has-key' : 'no-key'; ?>"></span>
								<span class="ptv-provider-name">Google Cloud TTS<span>Neural2 voices</span></span>
								<?php if ( 'google' === $tts_service ) : ?>
									<span class="ptv-active-badge"><?php esc_html_e( 'Active', 'zwh-post-to-voice' ); ?></span>
								<?php else : ?>
									<span class="ptv-key-badge <?php echo $api_key ? 'saved' : 'missing'; ?>">
										<?php echo $api_key ? esc_html__( 'Saved', 'zwh-post-to-voice' ) : esc_html__( 'No key', 'zwh-post-to-voice' ); ?>
									</span>
								<?php endif; ?>
							</li>
							<li>
								<span class="ptv-status-dot <?php echo ( $aws_access_key && $aws_secret_key ) ? 'has-key' : 'no-key'; ?>"></span>
								<span class="ptv-provider-name">Amazon Polly<span>AWS</span></span>
								<?php if ( 'amazon' === $tts_service ) : ?>
									<span class="ptv-active-badge"><?php esc_html_e( 'Active', 'zwh-post-to-voice' ); ?></span>
								<?php else : ?>
									<span class="ptv-key-badge <?php echo ( $aws_access_key && $aws_secret_key ) ? 'saved' : 'missing'; ?>">
										<?php echo ( $aws_access_key && $aws_secret_key ) ? esc_html__( 'Saved', 'zwh-post-to-voice' ) : esc_html__( 'No key', 'zwh-post-to-voice' ); ?>
									</span>
								<?php endif; ?>
							</li>
							<li>
								<span class="ptv-status-dot <?php echo $el_api_key ? 'has-key' : 'no-key'; ?>"></span>
								<span class="ptv-provider-name">ElevenLabs<span>Premium voices</span></span>
								<?php if ( 'elevenlabs' === $tts_service ) : ?>
									<span class="ptv-active-badge"><?php esc_html_e( 'Active', 'zwh-post-to-voice' ); ?></span>
								<?php else : ?>
									<span class="ptv-key-badge <?php echo $el_api_key ? 'saved' : 'missing'; ?>">
										<?php echo $el_api_key ? esc_html__( 'Saved', 'zwh-post-to-voice' ) : esc_html__( 'No key', 'zwh-post-to-voice' ); ?>
									</span>
								<?php endif; ?>
							</li>
						</ul>
					</div>

					<div class="ptv-sidebar-box">
						<h3><?php esc_html_e( 'Shortcode', 'zwh-post-to-voice' ); ?></h3>
						<p><code>[post_to_voice]</code></p>
						<p class="description"><?php esc_html_e( 'Place the player anywhere in a post. Optional: post_id="123" to show another post\'s audio.', 'zwh-post-to-voice' ); ?></p>
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
		// Register only — enqueued on demand by get_player_html() so the
		// assets load solely on pages that actually render the player.
		wp_register_style(
			'post-to-voice-frontend',
			POST_TO_VOICE_URL . 'frontend.css',
			array(),
			POST_TO_VOICE_VERSION
		);

		// Style-setting overrides + user custom CSS, loaded after the defaults.
		$inline_css = $this->build_inline_css();
		if ( $inline_css ) {
			wp_add_inline_style( 'post-to-voice-frontend', $inline_css );
		}

		wp_register_script(
			'post-to-voice-player',
			POST_TO_VOICE_URL . 'player.js',
			array(),
			POST_TO_VOICE_VERSION,
			true
		);
	}

	/**
	 * Build CSS from the style settings (only values that differ from the
	 * defaults) followed by the free-form custom CSS.
	 *
	 * @return string
	 */
	private function build_inline_css() {
		$vars = array();

		$accent = get_option( 'post_to_voice_accent_color', '' );
		if ( $accent && strtolower( $accent ) !== self::STYLE_DEFAULTS['accent'] ) {
			$vars[] = '--ptv-accent: ' . $accent . ';';
		}

		$accent2 = get_option( 'post_to_voice_accent_color_2', '' );
		if ( $accent2 && strtolower( $accent2 ) !== self::STYLE_DEFAULTS['accent_2'] ) {
			$vars[] = '--ptv-accent-2: ' . $accent2 . ';';
		}

		$text_color = get_option( 'post_to_voice_text_color', '' );
		if ( $text_color && strtolower( $text_color ) !== self::STYLE_DEFAULTS['text'] ) {
			$vars[] = '--ptv-text: ' . $text_color . ';';
		}

		$radius = get_option( 'post_to_voice_radius', '' );
		if ( '' !== $radius && false !== $radius && (int) $radius !== self::STYLE_DEFAULTS['radius'] ) {
			$vars[] = '--ptv-radius: ' . (int) $radius . 'px;';
		}

		$css = '';
		if ( $vars ) {
			$css .= '.ptv-player-container{' . implode( '', $vars ) . '}';
		}

		$custom = get_option( 'post_to_voice_custom_css', '' );
		if ( $custom ) {
			$css .= "\n" . $custom;
		}

		return $css;
	}

	public function add_audio_meta_box() {
		add_meta_box(
			'post_to_voice_meta',
			__( 'Audio Version', 'zwh-post-to-voice' ),
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
					<?php esc_html_e( 'Enable audio for this post', 'zwh-post-to-voice' ); ?>
				</label>
			</p>

			<?php if ( $audio_url ) : ?>
				<p>
					<span class="ptv-audio-label"><?php esc_html_e( 'Audio Attached', 'zwh-post-to-voice' ); ?></span><br />
					<audio controls style="width:100%; margin:10px 0;">
						<source src="<?php echo esc_url( $audio_url ); ?>" type="audio/mpeg">
					</audio>
					<button type="button" class="button button-small ptv-remove-audio" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
						<?php esc_html_e( 'Remove Audio', 'zwh-post-to-voice' ); ?>
					</button>
				</p>
			<?php else : ?>
				<p>
					<button type="button" class="button button-primary ptv-generate-audio" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
						<?php esc_html_e( 'Generate Audio', 'zwh-post-to-voice' ); ?>
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
				var $loading = $btn.closest('#ptv-meta-box').find('.ptv-loading');

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
		$text = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
		$text = preg_replace( '/\s+/', ' ', $text ); // Normalize whitespace
		$text = substr( $text, 0, 4000 ); // Limit for API

		// Generate audio using the selected TTS service
		$tts_service = get_option( 'post_to_voice_tts_service', 'google' );

		$providers = array(
			'google'     => 'Google_TTS',
			'amazon'     => 'Amazon_Polly_TTS',
			'elevenlabs' => 'ElevenLabs_TTS',
		);

		if ( ! isset( $providers[ $tts_service ] ) ) {
			wp_send_json_error( 'Unknown TTS service: ' . esc_html( $tts_service ) );
		}

		try {
			$class  = $providers[ $tts_service ];
			$tts    = new $class();
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

	/**
	 * Build the player HTML (+ AudioObject schema) for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string Empty string when audio is missing or disabled.
	 */
	public function get_player_html( $post_id ) {
		$enabled   = get_post_meta( $post_id, '_post_to_voice_enabled', true );
		$audio_url = get_post_meta( $post_id, '_post_to_voice_audio_url', true );

		if ( ! $enabled || ! $audio_url ) {
			return '';
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}

		// Load the player assets only when the player is actually rendered.
		wp_enqueue_style( 'post-to-voice-frontend' );
		wp_enqueue_script( 'post-to-voice-player' );

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

		return sprintf(
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
			esc_html__( 'Listen to this article', 'zwh-post-to-voice' ),
			$safe_audio_url,
			esc_html__( 'Your browser does not support the audio element.', 'zwh-post-to-voice' ),
			$schema_json
		);
	}

	/**
	 * [post_to_voice] shortcode — place the player anywhere.
	 *
	 * Attributes:
	 *   post_id (optional) — show another post's audio. Defaults to current post.
	 */
	public function render_shortcode( $atts ) {
		$atts = shortcode_atts( array(
			'post_id' => 0,
		), $atts, 'post_to_voice' );

		$post_id = absint( $atts['post_id'] );
		if ( ! $post_id ) {
			$post_id = get_the_ID();
		}
		if ( ! $post_id ) {
			return '';
		}

		return $this->get_player_html( $post_id );
	}

	/**
	 * Automatic player embed (the_content filter).
	 *
	 * Skipped when placement is "shortcode only" or the post already uses
	 * the [post_to_voice] shortcode.
	 */
	public function add_audio_player( $content ) {
		if ( ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$placement = get_option( 'post_to_voice_placement', 'before' );
		if ( 'shortcode' === $placement ) {
			return $content;
		}

		// The shortcode takes precedence over auto-embed.
		if ( has_shortcode( get_post()->post_content, 'post_to_voice' ) ) {
			return $content;
		}

		$player_html = $this->get_player_html( get_the_ID() );
		if ( ! $player_html ) {
			return $content;
		}

		return 'after' === $placement ? $content . $player_html : $player_html . $content;
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

// ---------------------------------------------------------------------------
// GitHub update system
// ---------------------------------------------------------------------------
require_once plugin_dir_path( __FILE__ ) . 'includes/class-zwh-github-updater.php';
new ZWH_GitHub_Updater( __FILE__, 'ziv-was-here/zwh-post-to-voice' );
