/**
 * WoW Raid Progress - Frontend JavaScript
 * Version: 5.0.0
 */

(function ($) {
	'use strict';

	$(document).ready(function () {

		// Initialize boss item hover effects
		initBossHoverEffects();

		// Initialize lazy loading for boss icons
		initLazyLoading();

		// Initialize tooltips
		initTooltips();

		adjustRaidTitleSize();

		var resizeTimer;
		$(window).on('resize', function () {
			clearTimeout(resizeTimer);
			resizeTimer = setTimeout(adjustRaidTitleSize, 250);
		});

		/**
		 * Initialize boss item hover effects
		 */
		function initBossHoverEffects() {
			$('.wow-boss-item').on('mouseenter', function () {
				$(this).addClass('hover');
			}).on('mouseleave', function () {
				$(this).removeClass('hover');
			});

			// Add click handler for mobile devices
			$('.wow-boss-item').on('click', function (e) {
				if ($(window).width() <= 768) {
					e.preventDefault();
					var $this = $(this);

					// Remove active from others
					$('.wow-boss-item').not($this).removeClass('active');

					// Toggle active on this item
					$this.toggleClass('active');
				}
			});
		}

		/**
		 * Initialize lazy loading for boss icons
		 */
		function initLazyLoading() {
			var lazyImages = $('.wow-boss-icon img[data-src]');

			if (lazyImages.length === 0) {
				return;
			}

			if ('IntersectionObserver' in window) {
				var imageObserver = new IntersectionObserver(function (entries) {
					entries.forEach(function (entry) {
						if (entry.isIntersecting) {
							var img = entry.target;
							img.src = img.dataset.src;
							img.removeAttribute('data-src');
							imageObserver.unobserve(img);
						}
					});
				});

				lazyImages.each(function () {
					imageObserver.observe(this);
				});
			} else {
				// Fallback: load all images immediately
				lazyImages.each(function () {
					var $img = $(this);
					$img.attr('src', $img.data('src'));
					$img.removeAttr('data-src');
				});
			}
		}

		/**
		 * Initialize tooltips for boss statistics
		 */
		function initTooltips() {
			// Add tooltip container if it doesn't exist
			if ($('#wow-raid-tooltip').length === 0) {
				$('body').append('<div id="wow-raid-tooltip" class="wow-raid-tooltip"></div>');
			}

			var $tooltip = $('#wow-raid-tooltip');

			// Tooltip for pulls
			$('.wow-pulls').on('mouseenter', function () {
				var $this = $(this);
				var text = 'Number of attempts on this boss';
				showTooltip($this, text, $tooltip);
			}).on('mouseleave', function () {
				hideTooltip($tooltip);
			});

			// Tooltip for best percentage
			$('.wow-percent').on('mouseenter', function () {
				var $this = $(this);
				var percent = $this.text().match(/[\d.]+/);
				if (percent) {
					var remaining = (100 - parseFloat(percent[0])).toFixed(1);
					var text = 'Boss health remaining: ' + remaining + '%';
					showTooltip($this, text, $tooltip);
				}
			}).on('mouseleave', function () {
				hideTooltip($tooltip);
			});

			// Tooltip for world rank
			$('.wow-rank:contains("World")').on('mouseenter', function () {
				var $this = $(this);
				var text = 'Global ranking across all regions';
				showTooltip($this, text, $tooltip);
			}).on('mouseleave', function () {
				hideTooltip($tooltip);
			});
		}

		/**
		 * Show tooltip
		 */
		function showTooltip($element, text, $tooltip) {
			$tooltip.text(text);

			var offset = $element.offset();
			var tooltipWidth = $tooltip.outerWidth();
			var tooltipHeight = $tooltip.outerHeight();

			// Position above the element by default
			var top = offset.top - tooltipHeight - 5;
			var left = offset.left + ($element.outerWidth() / 2) - (tooltipWidth / 2);

			// Check if tooltip would go off screen
			if (top < $(window).scrollTop()) {
				// Position below instead
				top = offset.top + $element.outerHeight() + 5;
			}

			if (left < 0) {
				left = 5;
			} else if (left + tooltipWidth > $(window).width()) {
				left = $(window).width() - tooltipWidth - 5;
			}

			$tooltip.css({
				top: top + 'px',
				left: left + 'px',
				opacity: 0,
				display: 'block'
			}).animate({ opacity: 1 }, 200);
		}

		/**
		 * Hide tooltip
		 */
		function hideTooltip($tooltip) {
			$tooltip.animate({ opacity: 0 }, 200, function () {
				$(this).hide();
			});
		}

		/**
		 * Auto-refresh functionality (optional)
		 */
		function initAutoRefresh() {
			var $containers = $('.wow-raid-progress-container[data-refresh]');

			$containers.each(function () {
				var $container = $(this);
				var refreshInterval = parseInt($container.data('refresh'), 10);

				if (refreshInterval && refreshInterval > 0) {
					// Minimum 5 minutes
					refreshInterval = Math.max(refreshInterval, 300) * 1000;

					setInterval(function () {
						refreshWidget($container);
					}, refreshInterval);
				}
			});
		}

		/**
		 * Refresh widget via AJAX
		 */
		function refreshWidget($container) {
			var shortcodeAttrs = $container.data('shortcode-attrs');

			if (!shortcodeAttrs) {
				return;
			}

			// Add loading indicator
			$container.addClass('loading');

			$.ajax({
				url: wow_raid_ajax.ajax_url,
				type: 'POST',
				data: {
					action: 'wow_raid_refresh_widget',
					attrs: shortcodeAttrs,
					nonce: wow_raid_ajax.nonce
				},
				success: function (response) {
					if (response.success && response.data) {
						var $newContent = $(response.data);
						$container.replaceWith($newContent);

						// Reinitialize features for new content
						initProgressAnimations();
						initBossHoverEffects();
						initLazyLoading();
						initTooltips();
					}
				},
				complete: function () {
					$container.removeClass('loading');
				}
			});
		}

		/**
		 * Handle responsive behavior
		 */
		function handleResponsive() {
			var $window = $(window);
			var $containers = $('.wow-raid-progress-container');

			function checkWidth() {
				if ($window.width() <= 768) {
					$containers.addClass('mobile-view');
				} else {
					$containers.removeClass('mobile-view');
				}
			}

			checkWidth();
			$window.on('resize', debounce(checkWidth, 250));
		}

		/**
		 * Debounce function for resize events
		 */
		function debounce(func, wait) {
			var timeout;
			return function () {
				var context = this, args = arguments;
				clearTimeout(timeout);
				timeout = setTimeout(function () {
					func.apply(context, args);
				}, wait);
			};
		}

		// Initialize responsive handling
		handleResponsive();

		// Optional: Initialize auto-refresh if needed
		// initAutoRefresh();

		/**
		 * Accessibility improvements
		 */
		function initAccessibility() {
			// Add ARIA labels
			$('.wow-progress-bar').attr('role', 'progressbar');
			$('.wow-progress-fill').each(function () {
				var $fill = $(this);
				var width = parseInt($fill.css('width'), 10);
				var maxWidth = $fill.parent().width();
				var percentage = Math.round((width / maxWidth) * 100);

				$fill.attr({
					'aria-valuenow': percentage,
					'aria-valuemin': '0',
					'aria-valuemax': '100',
					'aria-label': 'Progress: ' + percentage + '%'
				});
			});

			// Add keyboard navigation
			$('.wow-boss-item').attr('tabindex', '0').on('keypress', function (e) {
				if (e.which === 13 || e.which === 32) { // Enter or Space
					e.preventDefault();
					$(this).trigger('click');
				}
			});
		}

		// Initialize accessibility features
		initAccessibility();

		/**
		 * Adjust raid title font size to fit container
		 */
		function adjustRaidTitleSize() {
			$('.wow-raid-header-title[data-text]').each(function () {
				var $title = $(this);
				var $span = $title.find('span');
				var containerWidth = $title.width();
				var text = $title.data('text');

				// Reset to maximum size
				var maxSize = 32;
				var minSize = 18;
				var currentSize = maxSize;

				$span.css('font-size', currentSize + 'px');

				// Reduce size until it fits
				while ($span[0].scrollWidth > containerWidth && currentSize > minSize) {
					currentSize -= 1;
					$span.css('font-size', currentSize + 'px');
				}

				// Ensure no word breaks in the middle
				var words = text.split(' ');
				var longestWord = words.reduce(function (a, b) {
					return a.length > b.length ? a : b;
				}, '');

				// Create temporary element to measure longest word
				var $temp = $('<span>')
					.text(longestWord)
					.css({
						'position': 'absolute',
						'visibility': 'hidden',
						'font-size': currentSize + 'px',
						'font-weight': '700',
						'white-space': 'nowrap'
					})
					.appendTo('body');

				// Reduce further if longest word doesn't fit
				while ($temp.width() > containerWidth - 60 && currentSize > minSize) {
					currentSize -= 1;
					$temp.css('font-size', currentSize + 'px');
					$span.css('font-size', currentSize + 'px');
				}

				$temp.remove();
			});
		}

		/**
		 * Print optimization
		 */
		window.addEventListener('beforeprint', function () {
			$('.wow-raid-progress-container').addClass('print-mode');
			// Expand all collapsed sections
			$('.wow-boss-item').addClass('expanded');
		});

		window.addEventListener('afterprint', function () {
			$('.wow-raid-progress-container').removeClass('print-mode');
			$('.wow-boss-item').removeClass('expanded');
		});
	});

	// Public API for external use
	window.WoWRaidProgress = {
		refresh: function (selector) {
			var $container = $(selector);
			if ($container.length) {
				refreshWidget($container);
			}
		},

		updateProgress: function (selector, data) {
			// Allow external scripts to update progress data
			var $container = $(selector);
			if ($container.length && data) {
				// Implementation would go here
				console.log('Updating progress for:', selector, data);
			}
		}
	};

})(jQuery);

// Tooltip styles (injected dynamically)
(function () {
	var style = document.createElement('style');
	style.textContent = `
        .wow-raid-tooltip {
            position: absolute;
            background: rgba(0, 0, 0, 0.9);
            color: #fff;
            padding: 8px 12px;
            border-radius: 4px;
            font-size: 12px;
            line-height: 1.4;
            z-index: 9999;
            pointer-events: none;
            max-width: 200px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
            display: none;
        }

        .wow-raid-progress-container.loading {
            position: relative;
            opacity: 0.6;
            pointer-events: none;
        }

        .wow-raid-progress-container.loading::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 40px;
            height: 40px;
            border: 4px solid rgba(255, 255, 255, 0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: translate(-50%, -50%) rotate(360deg); }
        }

        .wow-raid-progress-container.mobile-view .wow-boss-item {
            padding: 12px;
        }

        .wow-raid-progress-container.mobile-view .wow-boss-item.active {
            background: rgba(255, 255, 255, 0.1);
        }

        .wow-raid-progress-container.print-mode {
            background: white !important;
            color: black !important;
        }
    `;
	document.head.appendChild(style);
})();
