/**
 * Encountrix - Admin JavaScript
 * Version: 5.0.1
 */

(function ($) {
	'use strict';

	$(document).ready(function () {
		// Initialize
		initTabs();
		adjustAllSelects();
		loadRaidsForExpansion();
		initIconImport();
		initIconDelete();
		initCacheClear();
		initRaidRefresh();
		initFormValidation();
		initRealmSelector();
		initShortcodeCopy();
		initDebugMode();

		// Tab switching functionality
		function initTabs() {
			$('.encountrix-tabs .nav-tab').on('click', function (e) {
				e.preventDefault();

				var targetId = $(this).attr('href');

				// Validate target
				if (!targetId || !$(targetId).length) {
					console.error('Invalid tab target:', targetId);
					return;
				}

				// Update active tab
				$('.encountrix-tabs .nav-tab').removeClass('nav-tab-active');
				$(this).addClass('nav-tab-active');

				// Show target content
				$('.tab-content').removeClass('active');
				$(targetId).addClass('active');

				// Save active tab to localStorage
				if (typeof (Storage) !== 'undefined') {
					localStorage.setItem('encountrix_active_tab', targetId);
				}
			});

			// Restore last active tab
			if (typeof (Storage) !== 'undefined') {
				var lastTab = localStorage.getItem('encountrix_active_tab');
				if (lastTab && $(lastTab).length) {
					$('.encountrix-tabs .nav-tab[href="' + lastTab + '"]').trigger('click');
				}
			}
		}

		// Load raids when expansion changes
		$('#encountrix_expansion').on('change', function () {
			loadRaidsForExpansion();
		});

		// Load raids for current expansion
		function loadRaidsForExpansion() {
			var expansionId = $('#encountrix_expansion').val();
			var currentRaid = $('#encountrix_raid').data('current-value') ||
				$('#encountrix_raid').val();

			if (!expansionId) {
				console.error('No expansion ID selected');
				return;
			}

			var $raidSelect = $('#encountrix_raid');
			$raidSelect.prop('disabled', true);
			$raidSelect.html('<option value="">' + encountrix_admin.strings.loading + '</option>');

			$.ajax({
				url: encountrix_admin.ajax_url,
				type: 'POST',
				data: {
					action: 'encountrix_get_raids',
					expansion_id: expansionId,
					nonce: encountrix_admin.nonce
				},
				success: function (response) {
					if (response.success && response.data) {
						var options = '<option value="">-- Select a Raid --</option>';

						$.each(response.data, function (index, raid) {
							var selected = (raid.slug === currentRaid) ? ' selected' : '';
							options += '<option value="' + escapeHtml(raid.slug) + '"' + selected + '>' +
								escapeHtml(raid.name) + '</option>';
						});

						$raidSelect.html(options);
						$raidSelect.prop('disabled', false);
						adjustSelectWidth($raidSelect);
					} else {
						$raidSelect.html('<option value="">' + encountrix_admin.strings.error_loading_raids + '</option>');
						showNotice(response.data || encountrix_admin.strings.failed_load_raids, 'error');
					}
				},
				error: function (xhr, status, error) {
					$raidSelect.html('<option value="">' + encountrix_admin.strings.error_loading_raids + '</option>');
					showNotice(encountrix_admin.strings.failed_load_raids + ': ' + error, 'error');
				}
			});
		}

		// Refresh raids button
		function initRaidRefresh() {
			$('#refresh-raids-btn').on('click', function () {
				var $btn = $(this);
				var originalText = $btn.text();

				$btn.prop('disabled', true);
				$btn.text(encountrix_admin.strings.loading);

				$.ajax({
					url: encountrix_admin.ajax_url,
					type: 'POST',
					data: {
						action: 'encountrix_refresh_raids',
						nonce: encountrix_admin.nonce
					},
					success: function (response) {
						if (response.success) {
							loadRaidsForExpansion();
							showNotice(encountrix_admin.strings.raids_refreshed_success, 'success');
						} else {
							showNotice(encountrix_admin.strings.failed_refresh_raids + ': ' + (response.data || encountrix_admin.strings.unknown_error), 'error');
						}
					},
					error: function (xhr, status, error) {
						showNotice(encountrix_admin.strings.failed_refresh_raids + ': ' + error, 'error');
					},
					complete: function () {
						$btn.prop('disabled', false);
						$btn.text(originalText);
					}
				});
			});
		}

		// Icon import functionality
		function initIconImport() {
			$('#import-icons-btn').on('click', function () {
				// Check if Blizzard API is configured
				var clientId = $('#encountrix_blizzard_client_id').val();
				var clientSecret = $('#encountrix_blizzard_client_secret').val();

				if (!clientId || !clientSecret) {
					showNotice(encountrix_admin.strings.configure_blizzard_api_first, 'error');
					$('.nav-tab[href="#blizzard"]').trigger('click');
					return;
				}

				// Check if a raid is selected
				var selectedRaid = $('#encountrix_raid').val();
				if (!selectedRaid) {
					showNotice(encountrix_admin.strings.select_raid_first, 'error');
					$('.nav-tab[href="#general"]').trigger('click');
					return;
				}

				if (!confirm(encountrix_admin.strings.confirm_import)) {
					return;
				}

				var $btn = $(this);
				var $status = $('#import-status');
				var originalText = $btn.text();

				$btn.prop('disabled', true);
				$btn.text(encountrix_admin.strings.importing);
				$status.show().removeClass('notice-success notice-error');
				$status.html('<strong>' + encountrix_admin.strings.starting_import + '</strong>');

				$.ajax({
					url: encountrix_admin.ajax_url,
					type: 'POST',
					data: {
						action: 'encountrix_import_icons',
						nonce: encountrix_admin.nonce
					},
					success: function (response) {
						$btn.prop('disabled', false);
						$btn.text(originalText);

						if (response.success && response.data.log) {
							var logHtml = '<strong>' + encountrix_admin.strings.import_complete + '</strong><br>';

							$.each(response.data.log, function (index, entry) {
								var className = 'log-entry';
								if (entry.indexOf('SUCCESS') === 0) {
									className += ' log-success';
								} else if (entry.indexOf('INFO') === 0) {
									className += ' log-info';
								} else if (entry.indexOf('ERROR') === 0) {
									className += ' log-error';
								}

								logHtml += '<div class="' + className + '">' +
									escapeHtml(entry) + '</div>';
							});

							$status.addClass('notice-success').html(logHtml);
						} else {
							$status.addClass('notice-error').html(
								'<strong>' + encountrix_admin.strings.error + '</strong> ' +
								(response.data || encountrix_admin.strings.unknown_error)
							);
						}
					},
					error: function (xhr, status, error) {
						$btn.prop('disabled', false);
						$btn.text(originalText);
						$status.addClass('notice-error').html(
							'<strong>' + encountrix_admin.strings.error + '</strong> ' + error
						);
					}
				});
			});
		}

		// Icon delete functionality
		function initIconDelete() {
			$('#delete-icons-btn').on('click', function () {
				if (!confirm(encountrix_admin.strings.confirm_delete_icons)) {
					return;
				}

				var $btn = $(this);
				var $message = $('#delete-icons-message');
				var originalText = $btn.text();

				$btn.prop('disabled', true);
				$btn.text(encountrix_admin.strings.deleting);

				$.ajax({
					url: encountrix_admin.ajax_url,
					type: 'POST',
					data: {
						action: 'encountrix_delete_icons',
						nonce: encountrix_admin.nonce
					},
					success: function (response) {
						if (response.success) {
							$message.text(response.data).fadeIn().delay(5000).fadeOut();
						} else {
							showNotice(encountrix_admin.strings.failed_delete_icons + ': ' + (response.data || encountrix_admin.strings.unknown_error), 'error');
						}
					},
					error: function (xhr, status, error) {
						showNotice(encountrix_admin.strings.failed_delete_icons + ': ' + error, 'error');
					},
					complete: function () {
						$btn.prop('disabled', false);
						$btn.text(originalText);
					}
				});
			});
		}

		// Cache clear functionality
		function initCacheClear() {
			$('#clear-cache-btn').on('click', function () {
				if (!confirm(encountrix_admin.strings.confirm_clear)) {
					return;
				}

				var $btn = $(this);
				var $message = $('#cache-clear-message');

				$btn.prop('disabled', true);

				$.ajax({
					url: encountrix_admin.ajax_url,
					type: 'POST',
					data: {
						action: 'encountrix_clear_cache',
						nonce: encountrix_admin.nonce
					},
					success: function (response) {
						if (response.success) {
							$message.fadeIn().delay(3000).fadeOut();
						} else {
							showNotice(encountrix_admin.strings.failed_clear_cache + ': ' + (response.data || encountrix_admin.strings.unknown_error), 'error');
						}
					},
					error: function (xhr, status, error) {
						showNotice(encountrix_admin.strings.failed_clear_cache + ': ' + error, 'error');
					},
					complete: function () {
						$btn.prop('disabled', false);
					}
				});
			});
		}

		// Form validation
		function initFormValidation() {
			$('form').on('submit', function (e) {
				var isValid = true;
				var errors = [];

				// Validate API key
				var apiKey = $('#encountrix_api_key').val();
				if (!apiKey) {
					if (!confirm(encountrix_admin.strings.no_api_key_continue)) {
						e.preventDefault();
						return false;
					}
				}

				// Validate guild IDs format
				var guildIds = $('#encountrix_guild_ids').val();
				if (guildIds) {
					var guilds = guildIds.split(',');
					var invalidGuilds = [];

					guilds.forEach(function (guild) {
						guild = guild.trim();
						if (guild && !/^\d+$/.test(guild)) {
							invalidGuilds.push(guild);
						}
					});

					if (invalidGuilds.length > 0) {
						errors.push(encountrix_admin.strings.invalid_guild_ids + ' ' + invalidGuilds.join(', ') + '. ' + encountrix_admin.strings.guild_ids_numeric);
						isValid = false;
					}

					if (guilds.length > 10) {
						errors.push(encountrix_admin.strings.max_guild_ids);
						isValid = false;
					}
				}

				// Validate realm name
				var realm = $('#encountrix_realm').val();
				if (realm && realm.length > 50) {
					errors.push(encountrix_admin.strings.realm_too_long);
					isValid = false;
				}

				// Validate cache time
				var $cacheField = $('#encountrix_cache_time');
				if ($cacheField.length) {
					var cacheTime = parseInt($cacheField.val());
					if (isNaN(cacheTime) || cacheTime < 0 || cacheTime > 1440) {
						errors.push(encountrix_admin.strings.cache_time_invalid);
						isValid = false;
					}
				}

				// Validate limit
				var $limitField = $('#encountrix_limit');
				if ($limitField.length) {
					var limit = parseInt($limitField.val());
					if (isNaN(limit) || limit < 1 || limit > 100) {
						errors.push(encountrix_admin.strings.limit_invalid);
						isValid = false;
					}
				}

				// Show errors if any
				if (!isValid) {
					e.preventDefault();
					var errorMsg = encountrix_admin.strings.please_fix_errors + '\n\n' + errors.join('\n');
					alert(errorMsg);
					return false;
				}

				return true;
			});
		}

		// Helper function to escape HTML
		function escapeHtml(text) {
			if (typeof text !== 'string') {
				return '';
			}

			var map = {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#039;'
			};

			return text.replace(/[&<>"']/g, function (m) {
				return map[m];
			});
		}

		// Helper function to show notices
		function showNotice(message, type) {
			var noticeClass = type === 'error' ? 'notice-error' : 'notice-success';
			var $notice = $('<div class="notice ' + noticeClass + ' is-dismissible">' +
				'<p>' + escapeHtml(message) + '</p>' +
				'<button type="button" class="notice-dismiss">' +
				'<span class="screen-reader-text">' + encountrix_admin.strings.dismiss_notice + '</span>' +
				'</button></div>');

			$('.encountrix-admin h1').after($notice);

			// Auto-dismiss after 5 seconds for success messages
			if (type === 'success') {
				setTimeout(function () {
					$notice.fadeOut(function () {
						$(this).remove();
					});
				}, 5000);
			}

			// Manual dismiss
			$notice.find('.notice-dismiss').on('click', function () {
				$notice.fadeOut(function () {
					$(this).remove();
				});
			});
		}

		// Auto width for selects
		function adjustSelectWidth($select) {
			if (!$select || !$select.length) return;

			try {
				var el = $select[0];
				var cs = window.getComputedStyle(el); // Changed from getComputedStyle to window.getComputedStyle

				// Create temporary span for measurement
				var span = document.createElement('span');
				span.style.position = 'absolute';
				span.style.visibility = 'hidden';
				span.style.whiteSpace = 'pre';
				span.style.font = cs.font;
				document.body.appendChild(span);

				var max = 0;
				$select.find('option').each(function () {
					span.textContent = this.text;
					max = Math.max(max, span.getBoundingClientRect().width);
				});
				document.body.removeChild(span);

				// Calculate total width with padding and arrow
				var extra =
					parseFloat(cs.paddingLeft) + parseFloat(cs.paddingRight) +
					parseFloat(cs.borderLeftWidth) + parseFloat(cs.borderRightWidth) +
					(parseFloat(cs.fontSize) * 1.6);

				var px = Math.ceil(max + extra);
				$select.css({ width: px + 'px', maxWidth: '100%' });
			} catch (e) {
				console.error('Error adjusting select width:', e);
			}
		}

		function adjustAllSelects() {
			$('select').each(function () {
				adjustSelectWidth($(this));
			});
		}

		function initRealmSelector() {
			var $regionSelect = $('#encountrix_region');
			var $realmInput = $('#encountrix_realm');
			var $dropdown = $('#realm-dropdown');
			var $realmList = $('#realm-list');
			var realmsData = [];
			var selectedRealm = $realmInput.val();

			// Load realms on page load
			loadRealmsForRegion($regionSelect.val());

			// Update realms when region changes
			$regionSelect.on('change', function () {
				var region = $(this).val();
				loadRealmsForRegion(region);
			});

			// Show dropdown on focus
			$realmInput.on('focus', function () {
				if (realmsData.length > 0) {
					filterRealms($(this).val());
					$dropdown.show();
				}
			});

			// Filter realms on input
			$realmInput.on('input', function () {
				filterRealms($(this).val());
			});

			// Hide dropdown on click outside
			$(document).on('click', function (e) {
				if (!$(e.target).closest('.encountrix-realm-selector').length) {
					$dropdown.hide();
				}
			});

			// Select realm on click
			$(document).on('click', '.encountrix-realm-item', function () {
				var slug = $(this).data('slug');
				var name = $(this).data('name');
				$realmInput.val(slug);
				$dropdown.hide();
			});

			// Keyboard navigation
			$realmInput.on('keydown', function (e) {
				var $items = $('.encountrix-realm-item:visible');
				var $active = $('.encountrix-realm-item.active');

				if (e.key === 'ArrowDown') {
					e.preventDefault();
					if ($active.length === 0) {
						$items.first().addClass('active');
					} else {
						$active.removeClass('active');
						var $next = $active.nextAll(':visible').first();
						if ($next.length) {
							$next.addClass('active');
						} else {
							$items.first().addClass('active');
						}
					}
					scrollToActive();
				} else if (e.key === 'ArrowUp') {
					e.preventDefault();
					if ($active.length === 0) {
						$items.last().addClass('active');
					} else {
						$active.removeClass('active');
						var $prev = $active.prevAll(':visible').first();
						if ($prev.length) {
							$prev.addClass('active');
						} else {
							$items.last().addClass('active');
						}
					}
					scrollToActive();
				} else if (e.key === 'Enter') {
					e.preventDefault();
					if ($active.length) {
						$active.trigger('click');
					}
				} else if (e.key === 'Escape') {
					$dropdown.hide();
				}
			});

			function loadRealmsForRegion(region) {
				$realmList.html('<div class="encountrix-realm-loading">' + encountrix_admin.strings.loading + '</div>');
				$dropdown.show();

				$.ajax({
					url: encountrix_admin.ajax_url,
					type: 'POST',
					data: {
						action: 'encountrix_get_realms',
						region: region,
						nonce: encountrix_admin.nonce
					},
					success: function (response) {
						if (response.success && response.data && response.data.length > 0) {
							realmsData = response.data;
							renderRealms(realmsData);

							// If there was a selected realm, try to keep it if it exists in new region
							if (selectedRealm) {
								var realmExists = realmsData.some(function (realm) {
									return realm.slug === selectedRealm;
								});
								if (!realmExists) {
									$realmInput.val('');
									selectedRealm = '';
								}
							}
						} else {
							// For KR/TW regions, provide helpful message
							realmsData = [];
							var message = encountrix_admin.strings.error_loading_realms;
							if (region === 'kr' || region === 'tw') {
								message += ' ' + encountrix_admin.strings.asian_region_note;
							}
							$realmList.html('<div class="encountrix-realm-error">' + message + '</div>');

							// Still allow manual input
							$realmInput.prop('disabled', false);
						}
					},
					error: function (xhr, status, error) {
						realmsData = [];
						var message = encountrix_admin.strings.error_loading_realms;

						// Add specific message for Asian regions
						if (region === 'kr' || region === 'tw') {
							message += ' ' + encountrix_admin.strings.asian_region_note;
						}

						$realmList.html('<div class="encountrix-realm-error">' + message + '</div>');

						// Log error for debugging
						console.error('Failed to load realms for region ' + region + ':', error);
					},
					complete: function () {
						// Hide dropdown if not focused
						if (!$realmInput.is(':focus')) {
							$dropdown.hide();
						}
					}
				});
			}

			function renderRealms(realms) {
				if (realms.length === 0) {
					$realmList.html('<div class="encountrix-realm-empty">' +
						encountrix_admin.strings.no_realms_found +
						'</div>');
					return;
				}

				var html = '';
				realms.forEach(function (realm) {
					html += '<div class="encountrix-realm-item" data-slug="' + escapeHtml(realm.slug) +
						'" data-name="' + escapeHtml(realm.name) + '">' +
						'<span class="realm-name">' + escapeHtml(realm.name) + '</span>' +
						'<span class="realm-slug">' + escapeHtml(realm.slug) + '</span>' +
						'</div>';
				});
				$realmList.html(html);
			}

			function filterRealms(query) {
				query = query.toLowerCase();

				if (query === '') {
					$('.encountrix-realm-item').show();
					return;
				}

				$('.encountrix-realm-item').each(function () {
					var $item = $(this);
					var name = $item.data('name').toLowerCase();
					var slug = $item.data('slug').toLowerCase();

					if (name.indexOf(query) !== -1 || slug.indexOf(query) !== -1) {
						$item.show();
					} else {
						$item.hide();
					}
				});

				// Show message if no results
				if ($('.encountrix-realm-item:visible').length === 0) {
					if ($('.encountrix-realm-no-results').length === 0) {
						$realmList.append('<div class="encountrix-realm-no-results">' +
							encountrix_admin.strings.no_matching_realms +
							'</div>');
					}
				} else {
					$('.encountrix-realm-no-results').remove();
				}
			}

			function scrollToActive() {
				var $active = $('.encountrix-realm-item.active');
				if ($active.length) {
					var container = $realmList[0];
					var activeTop = $active.position().top;
					var activeBottom = activeTop + $active.outerHeight();
					var scrollTop = container.scrollTop;
					var containerHeight = $(container).height();

					if (activeTop < 0) {
						container.scrollTop = scrollTop + activeTop;
					} else if (activeBottom > containerHeight) {
						container.scrollTop = scrollTop + activeBottom - containerHeight;
					}
				}
			}
		}

		function initShortcodeCopy() {
			$('.copy-shortcode').on('click', function (e) {
				e.preventDefault();

				var $btn = $(this);
				var $code = $btn.siblings('.shortcode-example');
				var shortcode = $code.data('shortcode') || $code.text();

				// Create temporary textarea
				var $temp = $('<textarea>');
				$('body').append($temp);
				$temp.val(shortcode).select();

				// Copy to clipboard
				var success = false;
				try {
					success = document.execCommand('copy');
				} catch (err) {
					console.error('Failed to copy:', err);
				}

				$temp.remove();

				// Update button feedback
				if (success) {
					var originalText = $btn.find('.copy-text').text();
					$btn.find('.copy-text').text(encountrix_admin.strings.copied);
					$btn.addClass('success');

					setTimeout(function () {
						$btn.find('.copy-text').text(originalText);
						$btn.removeClass('success');
					}, 2000);
				} else {
					// Fallback: select the text
					selectText($code[0]);
					showNotice(encountrix_admin.strings.press_ctrl_c, 'info');
				}
			});

			// Add click to select for shortcodes
			$('.shortcode-example').on('click', function () {
				selectText(this);
			});
		}

		function selectText(element) {
			var range, selection;

			if (document.body.createTextRange) {
				range = document.body.createTextRange();
				range.moveToElementText(element);
				range.select();
			} else if (window.getSelection) {
				selection = window.getSelection();
				range = document.createRange();
				range.selectNodeContents(element);
				selection.removeAllRanges();
				selection.addRange(range);
			}
		}

		// Initialize debug mode
		function initDebugMode() {
			if ($('#debug-log').length === 0) return;

			// Clear debug log
			$('#clear-debug-log').on('click', function () {
				$.ajax({
					url: encountrix_admin.ajax_url,
					type: 'POST',
					data: {
						action: 'encountrix_clear_debug_log',
						nonce: encountrix_admin.nonce
					},
					success: function () {
						$('.debug-log-content').html(encountrix_admin.strings.log_cleared);
					}
				});
			});

			// Test Blizzard API
			$('#test-blizzard-api').on('click', function () {
				var $btn = $(this);
				$btn.prop('disabled', true);

				$.ajax({
					url: encountrix_admin.ajax_url,
					type: 'POST',
					data: {
						action: 'encountrix_test_blizzard_api',
						nonce: encountrix_admin.nonce
					},
					success: function (response) {
						loadDebugLog();
					},
					complete: function () {
						$btn.prop('disabled', false);
					}
				});
			});

			// Load debug log
			function loadDebugLog() {
				$.ajax({
					url: encountrix_admin.ajax_url,
					type: 'POST',
					data: {
						action: 'encountrix_get_debug_log',
						nonce: encountrix_admin.nonce
					},
					success: function (response) {
						if (response.success && response.data) {
							var html = response.data.join('<br>');
							$('.debug-log-content').html(html || encountrix_admin.strings.no_log_entries);
						}
					}
				});
			}

			// Auto-refresh debug log
			if ($('#debug-log').length) {
				loadDebugLog();
				setInterval(loadDebugLog, 5000);
			}
		}

		$(window).on('resize', adjustAllSelects);
		$('select').on('change', function () {
			adjustSelectWidth($(this));
		});

		// Store current raid value
		var currentRaidValue = $('#encountrix_raid').val();
		if (currentRaidValue) {
			$('#encountrix_raid').data('current-value', currentRaidValue);
		}

		// Add tooltips for help icons
		$('.encountrix-help').on('mouseenter', function () {
			var $this = $(this);
			var helpText = $this.data('help');

			if (helpText) {
				var $tooltip = $('<div class="encountrix-tooltip">' + helpText + '</div>');
				$('body').append($tooltip);

				var offset = $this.offset();
				$tooltip.css({
					position: 'absolute',
					background: 'rgba(0, 0, 0, 0.8)',
					color: '#fff',
					padding: '8px 12px',
					borderRadius: '4px',
					fontSize: '12px',
					zIndex: 10000,
					maxWidth: '300px',
					top: offset.top - $tooltip.outerHeight() - 5,
					left: offset.left - ($tooltip.outerWidth() / 2) + ($this.outerWidth() / 2)
				}).fadeIn(200);

				$this.data('tooltip', $tooltip);
			}
		}).on('mouseleave', function () {
			var $tooltip = $(this).data('tooltip');
			if ($tooltip) {
				$tooltip.fadeOut(200, function () {
					$(this).remove();
				});
			}
		});

		// Add keyboard navigation for tabs
		$('.encountrix-tabs .nav-tab').on('keydown', function (e) {
			if (e.key === 'Enter' || e.key === ' ') {
				e.preventDefault();
				$(this).trigger('click');
			}
		});

		// Monitor for settings changes
		var settingsChanged = false;
		$('input, select, textarea').on('change', function () {
			settingsChanged = true;
		});

		// Warn before leaving with unsaved changes
		$(window).on('beforeunload', function () {
			if (settingsChanged) {
				return encountrix_admin.strings.unsaved_changes;
			}
		});

		// Reset flag on form submit
		$('form').on('submit', function () {
			settingsChanged = false;
		});
	});

})(jQuery);
