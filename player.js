/**
 * Post to Voice — custom player UI.
 *
 * Progressive enhancement: the markup ships a native <audio controls>
 * element. When JS runs, it hides the native controls and builds a custom
 * player (play/pause, seek bar, time, playback speed) that is fully
 * styleable via CSS.
 */

(function () {
	'use strict';

	var SPEEDS = [1, 1.25, 1.5, 2];

	function formatTime(sec) {
		if (!isFinite(sec) || sec < 0) {
			return '0:00';
		}
		var m = Math.floor(sec / 60);
		var s = Math.floor(sec % 60);
		return m + ':' + (s < 10 ? '0' : '') + s;
	}

	function el(tag, className, html) {
		var node = document.createElement(tag);
		if (className) {
			node.className = className;
		}
		if (html !== undefined) {
			node.innerHTML = html;
		}
		return node;
	}

	var PLAY_ICON = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5v14l11-7z" fill="currentColor"/></svg>';
	var PAUSE_ICON = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 5h4v14H6zM14 5h4v14h-4z" fill="currentColor"/></svg>';

	function buildPlayer(container) {
		var audio = container.querySelector('audio.ptv-audio-player');
		if (!audio) {
			return;
		}

		audio.removeAttribute('controls');

		var controls = el('div', 'ptv-controls');

		// Play / pause
		var playBtn = el('button', 'ptv-play-btn', PLAY_ICON);
		playBtn.type = 'button';
		playBtn.setAttribute('aria-label', 'Play');

		// Seek bar
		var seekWrap = el('div', 'ptv-seek-wrap');
		var seek = el('input', 'ptv-seek');
		seek.type = 'range';
		seek.min = 0;
		seek.max = 100;
		seek.step = 0.1;
		seek.value = 0;
		seek.setAttribute('aria-label', 'Seek');
		seekWrap.appendChild(seek);

		// Time display
		var time = el('span', 'ptv-time', '0:00 / 0:00');

		// Speed button
		var speedBtn = el('button', 'ptv-speed-btn', '1&times;');
		speedBtn.type = 'button';
		speedBtn.setAttribute('aria-label', 'Playback speed');
		var speedIndex = 0;

		controls.appendChild(playBtn);
		controls.appendChild(seekWrap);
		controls.appendChild(time);
		controls.appendChild(speedBtn);
		container.appendChild(controls);
		container.classList.add('ptv-enhanced');

		function updateTime() {
			time.textContent = formatTime(audio.currentTime) + ' / ' + formatTime(audio.duration);
			if (isFinite(audio.duration) && audio.duration > 0) {
				var pct = (audio.currentTime / audio.duration) * 100;
				seek.value = pct;
				seek.style.setProperty('--ptv-progress', pct + '%');
			}
		}

		playBtn.addEventListener('click', function () {
			if (audio.paused) {
				audio.play();
			} else {
				audio.pause();
			}
		});

		audio.addEventListener('play', function () {
			playBtn.innerHTML = PAUSE_ICON;
			playBtn.setAttribute('aria-label', 'Pause');
			container.classList.add('ptv-playing');
			// Pause any other players on the page.
			document.querySelectorAll('audio.ptv-audio-player').forEach(function (other) {
				if (other !== audio) {
					other.pause();
				}
			});
			if (window.gtag) {
				gtag('event', 'audio_play', {
					event_category: 'engagement',
					event_label: document.title
				});
			}
		});

		audio.addEventListener('pause', function () {
			playBtn.innerHTML = PLAY_ICON;
			playBtn.setAttribute('aria-label', 'Play');
			container.classList.remove('ptv-playing');
		});

		audio.addEventListener('timeupdate', updateTime);
		audio.addEventListener('loadedmetadata', updateTime);
		audio.addEventListener('ended', function () {
			audio.currentTime = 0;
		});

		seek.addEventListener('input', function () {
			if (isFinite(audio.duration)) {
				audio.currentTime = (seek.value / 100) * audio.duration;
			}
		});

		speedBtn.addEventListener('click', function () {
			speedIndex = (speedIndex + 1) % SPEEDS.length;
			audio.playbackRate = SPEEDS[speedIndex];
			speedBtn.innerHTML = String(SPEEDS[speedIndex]).replace('.', '.') + '&times;';
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('.ptv-player-container').forEach(buildPlayer);

		// Space bar toggles the first player when not focused on an input.
		document.addEventListener('keydown', function (e) {
			if ((e.key === ' ' || e.code === 'Space') && e.target === document.body) {
				var first = document.querySelector('audio.ptv-audio-player');
				if (first) {
					e.preventDefault();
					if (first.paused) {
						first.play();
					} else {
						first.pause();
					}
				}
			}
		});
	});
})();
