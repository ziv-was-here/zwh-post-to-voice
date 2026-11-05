=== ZWH Post to Voice ===
Contributors: yourname
Tags: audio, text-to-speech, tts, accessibility, voice
Requires at least: 5.0
Requires PHP: 7.4
Tested up to: 7.0
Stable tag: 1.3.0
Stable Tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Convert your WordPress posts to audio and embed a player at the top of each post.

== Description ==

Post to Voice is a WordPress plugin that converts your post text to spoken audio using Google Cloud Text-to-Speech API. The generated audio is automatically saved as a WordPress media attachment and displayed with a beautiful player at the top of each post.

**Features:**

* Convert post content to audio with one click
* Support for multiple voices and languages
* Audio files saved as WordPress media attachments
* Beautiful HTML5 audio player
* Per-post audio control (enable/disable)
* Free tier available (4M characters/month)
* AJAX-powered generation with real-time feedback
* Full WordPress security compliance

== Installation ==

1. Upload the `post-to-voice` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Admin → Post to Voice to configure your API key
4. Get a Google Cloud API key from https://console.cloud.google.com

== Configuration ==

1. Create a Google Cloud project
2. Enable the Text-to-Speech API
3. Create an API key
4. Paste the key into Plugin Settings
5. Choose your preferred voice and language

== Usage ==

1. Edit or create a post
2. Scroll to the "Audio Version" meta box
3. Click "Generate Audio"
4. Check "Enable audio for this post"
5. Publish/update
6. The audio player appears on the frontend

== Frequently Asked Questions ==

= Is this free? =
Google Cloud TTS has a free tier of 4 million characters per month, which covers most use cases.

= Which languages are supported? =
Any language supported by Google Cloud Text-to-Speech, including English, Spanish, French, German, Chinese, Japanese, and more.

= Where are audio files stored? =
Audio files are saved to /wp-content/uploads/post-to-voice/ and registered as WordPress media attachments.

= Can I delete audio files? =
Yes, click "Remove Audio" in the Audio Version meta box to delete the attachment and file.

== Changelog ==

= 1.3.0 =
* New: Custom post type support — choose which post types get the Audio Version box and auto-embedded player (Settings → Player → Post types)
* New: post_to_voice_post_types filter
* Fix: Generate/Remove audio now check edit permission on the specific post (works with CPTs that use custom capabilities)

= 1.2.0 =
* New: Amazon Polly support (Access Key/Secret, region, voice, neural/standard engine)
* New: ElevenLabs support (API key, voice ID, model selection)
* New: Custom player UI — play/pause, seek bar, time display, playback speed (1x–2x)
* New: Player style settings — accent/text colors, corner radius, custom CSS override
* New: [post_to_voice] shortcode to place the player anywhere (auto-embed stays default)
* New: Placement setting — before content, after content, or shortcode only


= 1.0.0 =
* Initial release
* Google Cloud TTS support
* WordPress media integration
* Beautiful player UI

== Support ==

For issues or questions, please contact support or visit the documentation.
