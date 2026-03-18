/**
 * Encountrix - Frontend JavaScript
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

		// Initialize optional auto-refresh
		initAutoRefresh();

		adjustRaidTitleSize();

		let resizeTimer;
		$(window).on('resize', function () {
			clearTimeout(resizeTimer);
			resizeTimer = setTimeout(adjustRaidTitleSize, 250);
		});

		/**
		 * Initialize boss item hover effects
		 */
		function initBossHoverEffects() {
			const $bossItems = $('.encountrix-boss-item');
			$bossItems.off('.encountrix');

			$bossItems.on('mouseenter.encountrix', function () {
				$(this).addClass('hover');
			}).on('mouseleave.encountrix', function () {
				$(this).removeClass('hover');
			});

			// Add click handler for mobile devices
			$bossItems.on('click.encountrix', function (e) {
				if ($(window).width() <= 768) {
					e.preventDefault();
					const $this = $(this);

					// Remove active from others
					$('.encountrix-boss-item').not($this).removeClass('active');

					// Toggle active on this item
					$this.toggleClass('active');
				}
			});
		}

		/**
		 * Initialize lazy loading for boss icons
		 */
		function initLazyLoading() {
			const lazyImages = $('.encountrix-boss-icon img[data-src]');

			if (lazyImages.length === 0) {
				return;
			}

			if ('IntersectionObserver' in window) {
				var imageObserver = new IntersectionObserver(function (entries) {
					entries.forEach(function (entry) {
						if (entry.isIntersecting) {
							const img = entry.target;
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
					const $img = $(this);
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
			if ($('#encountrix-tooltip').length === 0) {
				$('body').append('<div id="encountrix-tooltip" class="encountrix-tooltip"></div>');
			}

			const $tooltip = $('#encountrix-tooltip');

			// Tooltip for pulls
			$('.encountrix-pulls').off('.encountrix').on('mouseenter.encountrix', function () {
				const $this = $(this);
				const text = 'Number of attempts on this boss';
				showTooltip($this, text, $tooltip);
			}).on('mouseleave.encountrix', function () {
				hideTooltip($tooltip);
			});

			// Tooltip for best percentage
			$('.encountrix-percent').off('.encountrix').on('mouseenter.encountrix', function () {
				const $this = $(this);
				const percent = $this.text().match(/[\d.]+/);
				if (percent) {
					const remaining = (100 - parseFloat(percent[0])).toFixed(1);
					const text = 'Boss health remaining: ' + remaining + '%';
					showTooltip($this, text, $tooltip);
				}
			}).on('mouseleave.encountrix', function () {
				hideTooltip($tooltip);
			});

			// Tooltip for world rank
			$('.encountrix-rank:contains("World")').off('.encountrix').on('mouseenter.encountrix', function () {
				const $this = $(this);
				const text = 'Global ranking across all regions';
				showTooltip($this, text, $tooltip);
			}).on('mouseleave.encountrix', function () {
				hideTooltip($tooltip);
			});
		}

		/**
		 * Show tooltip
		 */
		function showTooltip($element, text, $tooltip) {
			$tooltip.text(text);

			const offset = $element.offset();
			const tooltipWidth = $tooltip.outerWidth();
			const tooltipHeight = $tooltip.outerHeight();

			// Position above the element by default
			let top = offset.top - tooltipHeight - 5;
			let left = offset.left + ($element.outerWidth() / 2) - (tooltipWidth / 2);

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
			const $containers = $('.encountrix-container[data-refresh]');

			$containers.each(function () {
				const $container = $(this);
				let $currentContainer = $container;
				let refreshInterval = parseInt($container.data('refresh'), 10);

				if (refreshInterval && refreshInterval > 0) {
					// Minimum 5 minutes
					refreshInterval = Math.max(refreshInterval, 300) * 1000;

					setInterval(function () {
						if (!$currentContainer || !$currentContainer.length || !$currentContainer.closest('body').length) {
							const shortcodeAttrs = $container.data('shortcode-attrs');
							if (shortcodeAttrs) {
								$currentContainer = $('.encountrix-container').filter(function () {
									return $(this).data('shortcode-attrs') === shortcodeAttrs;
								}).first();
							}
						}

						if ($currentContainer && $currentContainer.length) {
							refreshWidget($currentContainer, function ($replacement) {
								$currentContainer = $replacement;
							});
						}
					}, refreshInterval);
				}
			});
		}

		/**
		 * Refresh widget via AJAX
		 */
		function refreshWidget($container, onReplace) {
			const shortcodeAttrs = $container.data('shortcode-attrs');

			if (!shortcodeAttrs) {
				return;
			}

			// Add loading indicator
			$container.addClass('loading');

			$.ajax({
				url: encountrix_ajax.ajax_url,
				type: 'POST',
				data: {
					action: 'encountrix_refresh_widget',
					attrs: shortcodeAttrs,
					nonce: encountrix_ajax.nonce
				},
				success: function (response) {
					if (response.success && response.data) {
						const $newContent = $(response.data);
						$container.replaceWith($newContent);
						if (typeof onReplace === 'function') {
							onReplace($newContent);
						}

						// Reinitialize features for new content
						adjustRaidTitleSize();
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
			const $window = $(window);
			const $containers = $('.encountrix-container');

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
			let timeout;
			return function () {
				const context = this; const args = arguments;
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
			$('.encountrix-progress-bar').attr('role', 'progressbar');
			$('.encountrix-progress-fill').each(function () {
				const $fill = $(this);
				const width = parseInt($fill.css('width'), 10);
				const maxWidth = $fill.parent().width();
				const percentage = Math.round((width / maxWidth) * 100);

				$fill.attr({
					'aria-valuenow': percentage,
					'aria-valuemin': '0',
					'aria-valuemax': '100',
					'aria-label': 'Progress: ' + percentage + '%'
				});
			});

			// Add keyboard navigation
			$('.encountrix-boss-item').attr('tabindex', '0').on('keypress', function (e) {
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
			$('.encountrix-header-title[data-text]').each(function () {
				const $title = $(this);
				const $span = $title.find('.encountrix-header-title-text');
				const containerWidth = $title.width();
				const text = $title.data('text');
				const textLength = typeof text === 'string' ? text.trim().length : 0;

				// Start slightly smaller for longer tier names and tighten further only if needed.
				let maxSize = 30;
				const minSize = 17;

				if (textLength > 20) {
					maxSize = 28;
				}

				if (textLength > 28) {
					maxSize = 26;
				}

				let currentSize = maxSize;

				$span.css('font-size', currentSize + 'px');

				// Reduce size until it fits
				while ($span[0].scrollWidth > containerWidth && currentSize > minSize) {
					currentSize -= 1;
					$span.css('font-size', currentSize + 'px');
				}

				// Ensure no word breaks in the middle
				const words = text.split(' ');
				const longestWord = words.reduce(function (a, b) {
					return a.length > b.length ? a : b;
				}, '');

				// Create temporary element to measure longest word
				const $temp = $('<span>')
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
			$('.encountrix-container').addClass('print-mode');
			// Expand all collapsed sections
			$('.encountrix-boss-item').addClass('expanded');
		});

		window.addEventListener('afterprint', function () {
			$('.encountrix-container').removeClass('print-mode');
			$('.encountrix-boss-item').removeClass('expanded');
		});

		// Public API for external use
		window.Encountrix = {
			refresh: function (selector) {
				const $container = $(selector);
				if ($container.length) {
					refreshWidget($container);
				}
			},

			updateProgress: function (selector, data) {
				// Allow external scripts to update progress data
				const $container = $(selector);
				if ($container.length && data) {
					// Implementation would go here
					console.log('Updating progress for:', selector, data);
				}
			}
		};
	});
})(jQuery);

// Tooltip styles (injected dynamically)
(function () {
	const style = document.createElement('style');
	style.textContent = `
        .encountrix-tooltip {
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

        .encountrix-container.loading {
            position: relative;
            opacity: 0.6;
            pointer-events: none;
        }

        .encountrix-container.loading::after {
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

        .encountrix-container.mobile-view .encountrix-boss-item {
            padding: 12px;
        }

        .encountrix-container.mobile-view .encountrix-boss-item.active {
            background: rgba(255, 255, 255, 0.1);
        }

        .encountrix-container.print-mode {
            background: white !important;
            color: black !important;
        }
    `;
	document.head.appendChild(style);
})();
