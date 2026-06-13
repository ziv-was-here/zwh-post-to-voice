/**
 * Post to Voice Player Enhancements
 */

(function() {
	'use strict';

	document.addEventListener('DOMContentLoaded', function() {
		const players = document.querySelectorAll('.ptv-audio-player');

		players.forEach(function(player) {
			// Store current time for pause/resume
			let lastPlayedTime = 0;

			player.addEventListener('play', function() {
				// Pause all other players
				players.forEach(function(p) {
					if (p !== player) {
						p.pause();
					}
				});
			});

			// Optional: Analytics - track when audio is played
			player.addEventListener('play', function() {
				if (window.gtag) {
					gtag('event', 'audio_play', {
						event_category: 'engagement',
						event_label: document.title,
					});
				}
			});
		});

		// Keyboard shortcut to play/pause
		document.addEventListener('keydown', function(e) {
			if ((e.key === ' ' || e.code === 'Space') && e.target === document.body) {
				const firstPlayer = document.querySelector('.ptv-audio-player');
				if (firstPlayer) {
					e.preventDefault();
					if (firstPlayer.paused) {
						firstPlayer.play();
					} else {
						firstPlayer.pause();
					}
				}
			}
		});
	});
})();
