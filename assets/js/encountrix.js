/**
 * Encountrix - Frontend JavaScript
 * Version: 5.0.1
 */

(function ($) {
	'use strict';

	$(document).ready(function () {
		initBossHoverEffects();
		initLazyLoading();
		initTooltips();
		adjustRaidTitleSize();

		var resizeTimer;
		$(window).on('resize', function () {
			clearTimeout(resizeTimer);
			resizeTimer = setTimeout(adjustRaidTitleSize, 250);
		});

		function initBossHoverEffects() {
			$('.encountrix-boss-item').on('mouseenter', function () {
				$(this).addClass('hover');
			}).on('mouseleave', function () {
				$(this).removeClass('hover');
			});
			$('.encountrix-boss-item').on('click', function (e) {
				if ($(window).width() <= 768) {
					e.preventDefault();
					var $this = $(this);
					$('.encountrix-boss-item').not($this).removeClass('active');
					$this.toggleClass('active');
				}
			});
		}

		function initLazyLoading() {
			var lazyImages = $('.encountrix-boss-icon img[data-src]');
			if (lazyImages.length === 0) { return; }
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
				lazyImages.each(function () { imageObserver.observe(this); });
			} else {
				lazyImages.each(function () { var $img = $(this); $img.attr('src', $img.data('src')); $img.removeAttr('data-src'); });
			}
		}

		function initTooltips() {
			if ($('#encountrix-tooltip').length === 0) {
				$('body').append('<div id="encountrix-tooltip" class="encountrix-tooltip"></div>');
			}
			var $tooltip = $('#encountrix-tooltip');
			$('.encountrix-pulls').on('mouseenter', function () {
				showTooltip($(this), 'Number of attempts on this boss', $tooltip);
			}).on('mouseleave', function () { hideTooltip($tooltip); });

			$('.encountrix-percent').on('mouseenter', function () {
				var percent = $(this).text().match(/[\d.]+/);
				if (percent) { showTooltip($(this), 'Boss health remaining: ' + (100 - parseFloat(percent[0])).toFixed(1) + '%', $tooltip); }
			}).on('mouseleave', function () { hideTooltip($tooltip); });

			$('.encountrix-rank:contains("World")').on('mouseenter', function () {
				showTooltip($(this), 'Global ranking across all regions', $tooltip);
			}).on('mouseleave', function () { hideTooltip($tooltip); });
		}

		function showTooltip($element, text, $tooltip) {
			$tooltip.text(text);
			var offset = $element.offset();
			var tooltipWidth = $tooltip.outerWidth();
			var tooltipHeight = $tooltip.outerHeight();
			var top = offset.top - tooltipHeight - 5;
			var left = offset.left + ($element.outerWidth() / 2) - (tooltipWidth / 2);
			if (top < $(window).scrollTop()) { top = offset.top + $element.outerHeight() + 5; }
			if (left < 0) { left = 5; } else if (left + tooltipWidth > $(window).width()) { left = $(window).width() - tooltipWidth - 5; }
			$tooltip.css({ top: top + 'px', left: left + 'px', opacity: 0, display: 'block' }).animate({ opacity: 1 }, 200);
		}

		function hideTooltip($tooltip) {
			$tooltip.animate({ opacity: 0 }, 200, function () { $(this).hide(); });
		}

		function initAutoRefresh() {
			var $containers = $('.encountrix-container[data-refresh]');
			$containers.each(function () {
				var $container = $(this);
				var refreshInterval = parseInt($container.data('refresh'), 10);
				if (refreshInterval && refreshInterval > 0) {
					refreshInterval = Math.max(refreshInterval, 300) * 1000;
					setInterval(function () { refreshWidget($container); }, refreshInterval);
				}
			});
		}

		function refreshWidget($container) {
			var shortcodeAttrs = $container.data('shortcode-attrs');
			if (!shortcodeAttrs) { return; }
			$container.addClass('loading');
			$.ajax({
				url: encountrix_ajax.ajax_url, type: 'POST',
				data: { action: 'encountrix_refresh_widget', attrs: shortcodeAttrs, nonce: encountrix_ajax.nonce },
				success: function (response) {
					if (response.success && response.data) {
						var $newContent = $(response.data);
						$container.replaceWith($newContent);
						initBossHoverEffects(); initLazyLoading(); initTooltips();
					}
				},
				complete: function () { $container.removeClass('loading'); }
			});
		}

		function handleResponsive() {
			var $window = $(window);
			var $containers = $('.encountrix-container');
			function checkWidth() {
				if ($window.width() <= 768) { $containers.addClass('mobile-view'); } else { $containers.removeClass('mobile-view'); }
			}
			checkWidth();
			$window.on('resize', debounce(checkWidth, 250));
		}

		function debounce(func, wait) {
			var timeout;
			return function () {
				var context = this, args = arguments;
				clearTimeout(timeout);
				timeout = setTimeout(function () { func.apply(context, args); }, wait);
			};
		}

		handleResponsive();

		function initAccessibility() {
			$('.encountrix-progress-bar').attr('role', 'progressbar');
			$('.encountrix-progress-fill').each(function () {
				var $fill = $(this);
				var width = parseInt($fill.css('width'), 10);
				var maxWidth = $fill.parent().width();
				var percentage = Math.round((width / maxWidth) * 100);
				$fill.attr({ 'aria-valuenow': percentage, 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-label': 'Progress: ' + percentage + '%' });
			});
			$('.encountrix-boss-item').attr('tabindex', '0').on('keypress', function (e) {
				if (e.which === 13 || e.which === 32) { e.preventDefault(); $(this).trigger('click'); }
			});
		}
		initAccessibility();

		function adjustRaidTitleSize() {
			$('.encountrix-header-title[data-text]').each(function () {
				var $title = $(this); var $span = $title.find('span');
				var containerWidth = $title.width(); var text = $title.data('text');
				var maxSize = 32; var minSize = 18; var currentSize = maxSize;
				$span.css('font-size', currentSize + 'px');
				while ($span[0].scrollWidth > containerWidth && currentSize > minSize) { currentSize -= 1; $span.css('font-size', currentSize + 'px'); }
				var words = text.split(' ');
				var longestWord = words.reduce(function (a, b) { return a.length > b.length ? a : b; }, '');
				var $temp = $('<span>').text(longestWord).css({ 'position': 'absolute', 'visibility': 'hidden', 'font-size': currentSize + 'px', 'font-weight': '700', 'white-space': 'nowrap' }).appendTo('body');
				while ($temp.width() > containerWidth - 60 && currentSize > minSize) { currentSize -= 1; $temp.css('font-size', currentSize + 'px'); $span.css('font-size', currentSize + 'px'); }
				$temp.remove();
			});
		}

		window.addEventListener('beforeprint', function () { $('.encountrix-container').addClass('print-mode'); $('.encountrix-boss-item').addClass('expanded'); });
		window.addEventListener('afterprint', function () { $('.encountrix-container').removeClass('print-mode'); $('.encountrix-boss-item').removeClass('expanded'); });
	});

	window.Encountrix = {
		refresh: function (selector) { var $container = $(selector); if ($container.length) { refreshWidget($container); } },
		updateProgress: function (selector, data) { var $container = $(selector); if ($container.length && data) { console.log('Updating progress for:', selector, data); } }
	};
})(jQuery);

(function () {
	var style = document.createElement('style');
	style.textContent = `
        .encountrix-tooltip {
            position: absolute; background: rgba(0, 0, 0, 0.9); color: #fff; padding: 8px 12px;
            border-radius: 4px; font-size: 12px; line-height: 1.4; z-index: 9999;
            pointer-events: none; max-width: 200px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3); display: none;
        }
        .encountrix-container.loading {
            position: relative; opacity: 0.6; pointer-events: none;
        }
        .encountrix-container.loading::after {
            content: ''; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
            width: 40px; height: 40px; border: 4px solid rgba(255, 255, 255, 0.3);
            border-top-color: #fff; border-radius: 50%; animation: spin 1s linear infinite;
        }
        @keyframes spin { to { transform: translate(-50%, -50%) rotate(360deg); } }
        .encountrix-container.mobile-view .encountrix-boss-item { padding: 12px; }
        .encountrix-container.mobile-view .encountrix-boss-item.active { background: rgba(255, 255, 255, 0.1); }
        .encountrix-container.print-mode { background: white !important; color: black !important; }
    `;
	document.head.appendChild(style);
})();
