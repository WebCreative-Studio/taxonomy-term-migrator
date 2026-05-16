(function ($) {
	'use strict';

	var running = false;
	var cleanupRunning = false;
	var cleanupTotals = { deleted_terms: 0, deleted_attributes: 0, cleaned_products: 0, errors: [] };

	function getFormData() {
		var $form = $('#ttm-form');
		return {
			source_taxonomy: $('#ttm-source').val(),
			target_taxonomy: $('#ttm-target').val(),
			batch_size: $('#ttm-batch-size').val(),
			create_missing_terms: $form.find('[name="create_missing_terms"]').is(':checked') ? 1 : 0,
			transfer_relations: $form.find('[name="transfer_relations"]').is(':checked') ? 1 : 0,
			remove_old_relations: $form.find('[name="remove_old_relations"]').is(':checked') ? 1 : 0,
			delete_empty_source_terms: $form.find('[name="delete_empty_source_terms"]').is(':checked') ? 1 : 0,
			confirm_delete_terms: $form.find('[name="confirm_delete_terms"]').is(':checked') ? 1 : 0,
			cleanup_wc_attributes_after: $form.find('[name="cleanup_wc_attributes_after"]').is(':checked') ? 1 : 0,
			preserve_slug: $form.find('[name="preserve_slug"]').is(':checked') ? 1 : 0,
			enable_logging: $form.find('[name="enable_logging"]').is(':checked') ? 1 : 0,
			nonce: ttmAdmin.nonce,
			action: ''
		};
	}

	function validateTaxonomies() {
		var source = $('#ttm-source').val();
		var target = $('#ttm-target').val();

		if (!source || !target) {
			showNotice(ttmAdmin.i18n.selectTaxonomies, 'error');
			return false;
		}

		if (source === target) {
			showNotice(ttmAdmin.i18n.sameTaxonomy, 'error');
			return false;
		}

		return true;
	}

	function showNotice(message, type) {
		var $notice = $('#ttm-notice');
		$notice
			.removeClass('notice-success notice-error notice-info')
			.addClass('notice notice-' + (type || 'info'))
			.html('<p>' + escapeHtml(message) + '</p>')
			.show();
	}

	function escapeHtml(text) {
		return $('<div/>').text(text).html();
	}

	function ajaxRequest(action, extraData) {
		var data = getFormData();
		data.action = action;

		if (extraData) {
			$.extend(data, extraData);
		}

		return $.post(ttmAdmin.ajaxUrl, data);
	}

	function setRunning(isRunning) {
		running = isRunning;
		$('#ttm-save, #ttm-preview, #ttm-start, #ttm-source, #ttm-target, #ttm-batch-size, .ttm-checkboxes input').prop('disabled', isRunning);
		$('#ttm-stop').prop('disabled', !isRunning);
		getCleanupButtons().prop('disabled', isRunning || cleanupRunning);
	}

	function getCleanupButtons() {
		return $('#ttm-delete-empty-terms, #ttm-delete-empty-attributes, #ttm-cleanup-products, #ttm-full-cleanup');
	}

	function resetCleanupTotals() {
		cleanupTotals = { deleted_terms: 0, deleted_attributes: 0, cleaned_products: 0, errors: [] };
	}

	function mergeCleanupResult(result) {
		if (!result) {
			return;
		}
		cleanupTotals.deleted_terms += result.deleted_terms || 0;
		cleanupTotals.deleted_attributes += result.deleted_attributes || 0;
		cleanupTotals.cleaned_products += result.cleaned_products || 0;
		if (result.errors && result.errors.length) {
			cleanupTotals.errors = cleanupTotals.errors.concat(result.errors);
		}
	}

	function setCleanupRunning(isRunning) {
		cleanupRunning = isRunning;
		getCleanupButtons().prop('disabled', isRunning || running);
	}

	function showCleanupProgress(title, status, processed, total, indeterminate) {
		$('#ttm-cleanup-report').hide();
		$('#ttm-cleanup-progress').show();
		$('.ttm-cleanup-title strong').text(title || '');
		$('.ttm-cleanup-status').text(status || ttmAdmin.i18n.cleanupRunning);

		var $fill = $('.ttm-cleanup-progress-fill');
		if (indeterminate || !total) {
			$fill.addClass('ttm-progress-indeterminate').css('width', '40%');
			$('.ttm-cleanup-progress-text').text(ttmAdmin.i18n.cleanupPleaseWait);
			return;
		}

		$fill.removeClass('ttm-progress-indeterminate');
		var percent = total > 0 ? Math.round((processed / total) * 100) : 0;
		$fill.css('width', percent + '%');
		$('.ttm-cleanup-progress-text').text(processed + ' / ' + total + ' (' + percent + '%)');
	}

	function hideCleanupProgress() {
		$('#ttm-cleanup-progress').hide();
		$('.ttm-cleanup-progress-fill').removeClass('ttm-progress-indeterminate').css('width', '0%');
	}

	function renderCleanupReport(result, isError, message) {
		hideCleanupProgress();
		var $report = $('#ttm-cleanup-report');
		$report.removeClass('is-error');
		if (isError) {
			$report.addClass('is-error');
		}

		var html = '';
		if (message) {
			html += '<p>' + escapeHtml(message) + '</p>';
		}

		html += '<ul class="ttm-stats">';
		var hasStats = false;

		if (result && result.deleted_terms) {
			hasStats = true;
			html += '<li>' + escapeHtml(ttmAdmin.i18n.cleanupDeletedTerms) + ': <strong>' + result.deleted_terms + '</strong></li>';
		}
		if (result && result.deleted_attributes) {
			hasStats = true;
			html += '<li>' + escapeHtml(ttmAdmin.i18n.cleanupDeletedAttributes) + ': <strong>' + result.deleted_attributes + '</strong></li>';
		}
		if (result && result.cleaned_products) {
			hasStats = true;
			html += '<li>' + escapeHtml(ttmAdmin.i18n.cleanupCleanedProducts) + ': <strong>' + result.cleaned_products + '</strong></li>';
		}
		if (!hasStats && !isError) {
			html += '<li>' + escapeHtml(ttmAdmin.i18n.cleanupNothingFound) + '</li>';
		}
		if (result && result.errors && result.errors.length) {
			html += '<li>' + escapeHtml(ttmAdmin.i18n.cleanupErrors) + ': <strong>' + result.errors.length + '</strong></li>';
		}
		html += '</ul>';

		$('#ttm-cleanup-report-content').html(html);
		$report.show();

		var el = $report.get(0);
		if (el && el.scrollIntoView) {
			el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
		}
	}

	function runCleanup(action, confirmMessage, title) {
		if (running || cleanupRunning) {
			showNotice(ttmAdmin.i18n.migrationBlocksCleanup, 'error');
			return;
		}

		if (!window.confirm(confirmMessage)) {
			return;
		}

		resetCleanupTotals();
		setCleanupRunning(true);
		showCleanupProgress(title, ttmAdmin.i18n.cleanupRunning, 0, 0, true);

		$.post(ttmAdmin.ajaxUrl, {
			action: action,
			nonce: ttmAdmin.nonce
		})
			.done(function (response) {
				if (response.success) {
					mergeCleanupResult(response.data && response.data.result ? response.data.result : null);
					renderCleanupReport(cleanupTotals, false, response.data && response.data.message ? response.data.message : ttmAdmin.i18n.cleanupCompleted);
					showNotice(ttmAdmin.i18n.cleanupCompleted, 'success');
				} else {
					renderCleanupReport(null, true, response.data && response.data.message ? response.data.message : ttmAdmin.i18n.error);
					showNotice(response.data && response.data.message ? response.data.message : ttmAdmin.i18n.error, 'error');
				}
			})
			.fail(function () {
				renderCleanupReport(null, true, ttmAdmin.i18n.error);
				showNotice(ttmAdmin.i18n.error, 'error');
			})
			.always(function () {
				setCleanupRunning(false);
			});
	}

	function runFullCleanupManual() {
		if (running || cleanupRunning) {
			showNotice(ttmAdmin.i18n.migrationBlocksCleanup, 'error');
			return;
		}

		if (!window.confirm(ttmAdmin.i18n.confirmFullCleanup)) {
			return;
		}

		resetCleanupTotals();
		setCleanupRunning(true);

		function postAction(action) {
			return $.post(ttmAdmin.ajaxUrl, { action: action, nonce: ttmAdmin.nonce });
		}

		showCleanupProgress(ttmAdmin.i18n.confirmFullCleanup, ttmAdmin.i18n.cleanupStepTerms, 0, 0, true);

		postAction('ttm_delete_empty_attribute_terms')
			.done(function (response) {
				if (!response.success) {
					renderCleanupReport(null, true, response.data && response.data.message ? response.data.message : ttmAdmin.i18n.error);
					setCleanupRunning(false);
					return;
				}
				mergeCleanupResult(response.data.result);
				showCleanupProgress('', ttmAdmin.i18n.cleanupStepAttributes, 1, 3, false);

				postAction('ttm_delete_empty_attributes')
					.done(function (response2) {
						if (!response2.success) {
							renderCleanupReport(cleanupTotals, true, response2.data && response2.data.message ? response2.data.message : ttmAdmin.i18n.error);
							setCleanupRunning(false);
							return;
						}
						mergeCleanupResult(response2.data.result);
						runProductCleanupBatched(0, ttmAdmin.i18n.cleanupStepProducts, function () {
							renderCleanupReport(cleanupTotals, false, ttmAdmin.i18n.cleanupCompleted);
							showNotice(ttmAdmin.i18n.cleanupCompleted, 'success');
							setCleanupRunning(false);
						});
					})
					.fail(function () {
						renderCleanupReport(cleanupTotals, true, ttmAdmin.i18n.error);
						setCleanupRunning(false);
					});
			})
			.fail(function () {
				renderCleanupReport(null, true, ttmAdmin.i18n.error);
				setCleanupRunning(false);
			});
	}

	function runProductCleanupBatched(offset, title, onDone) {
		$.post(ttmAdmin.ajaxUrl, {
			action: 'ttm_cleanup_product_attributes',
			nonce: ttmAdmin.nonce,
			offset: offset
		})
			.done(function (response) {
				if (!response.success || !response.data.batch) {
					if (onDone) {
						onDone(true);
					}
					return;
				}

				var batch = response.data.batch;
				cleanupTotals.cleaned_products += batch.cleaned_products || 0;
				showCleanupProgress(title, ttmAdmin.i18n.cleanupStepProducts, batch.next_offset || 0, batch.total || 0, false);

				if (batch.has_more) {
					runProductCleanupBatched(batch.next_offset, title, onDone);
					return;
				}

				if (onDone) {
					onDone(false);
				}
			})
			.fail(function () {
				if (onDone) {
					onDone(true);
				}
			});
	}

	function updateProgress(state) {
		var processed = state.processed || 0;
		var total = state.total || 0;
		var percent = total > 0 ? Math.round((processed / total) * 100) : 0;

		$('#ttm-progress').show();
		$('.ttm-progress-fill').css('width', percent + '%');
		$('.ttm-progress-text').text(processed + ' / ' + total);
	}

	function renderPreview(data) {
		var summary =
			'<ul class="ttm-stats">' +
			'<li><strong>Донорская таксономия:</strong> ' + escapeHtml(data.source_taxonomy) + '</li>' +
			'<li><strong>Целевая таксономия:</strong> ' + escapeHtml(data.target_taxonomy) + '</li>' +
			'<li><strong>Найдено терминов:</strong> ' + data.term_count + '</li>' +
			'<li><strong>Найдено товаров со связями:</strong> ' + data.product_count + '</li>' +
			'<li><strong>Будет создано новых терминов:</strong> ' + data.will_create_terms + '</li>' +
			'<li><strong>Уже существуют в целевой таксономии:</strong> ' + data.existing_terms + '</li>' +
			'<li><strong>Потенциальные конфликты slug:</strong> ' + data.slug_conflicts + '</li>' +
			'</ul>';

		$('#ttm-preview-summary').html(summary);

		var rows = '';
		(data.rows || []).forEach(function (row) {
			rows +=
				'<tr>' +
				'<td>' + escapeHtml(row.source_name) + '</td>' +
				'<td>' + escapeHtml(row.slug) + '</td>' +
				'<td>' + escapeHtml(row.target_name) + '</td>' +
				'<td>' + escapeHtml(row.status_label) + '</td>' +
				'</tr>';
		});

		$('#ttm-preview-rows').html(rows);
		$('#ttm-preview-panel').show();
		showNotice('Предпросмотр готов. Проверьте таблицу перед запуском.', 'info');
	}

	function renderReport(summary) {
		var html =
			'<p><strong>' + escapeHtml(ttmAdmin.i18n.completed) + '</strong></p>' +
			'<ul class="ttm-stats">' +
			'<li>' + escapeHtml(ttmAdmin.i18n.processed) + ': ' + summary.processed + '</li>' +
			'<li>' + escapeHtml(ttmAdmin.i18n.createdTerms) + ': ' + summary.created_terms + '</li>' +
			'<li>' + escapeHtml(ttmAdmin.i18n.usedExisting) + ': ' + summary.used_existing_terms + '</li>' +
			'<li>' + escapeHtml(ttmAdmin.i18n.linkedProducts) + ': ' + summary.linked_products + '</li>' +
			'<li>' + escapeHtml(ttmAdmin.i18n.removedRelations) + ': ' + summary.removed_relations + '</li>' +
			'<li>' + escapeHtml(ttmAdmin.i18n.errorsCount) + ': ' + summary.errors_count + '</li>';

		if (summary.cleanup_deleted_terms) {
			html += '<li>' + escapeHtml(ttmAdmin.i18n.cleanupDeletedTerms) + ': ' + summary.cleanup_deleted_terms + '</li>';
		}
		if (summary.cleanup_deleted_attributes) {
			html += '<li>' + escapeHtml(ttmAdmin.i18n.cleanupDeletedAttributes) + ': ' + summary.cleanup_deleted_attributes + '</li>';
		}
		if (summary.cleanup_cleaned_products) {
			html += '<li>' + escapeHtml(ttmAdmin.i18n.cleanupCleanedProducts) + ': ' + summary.cleanup_cleaned_products + '</li>';
		}

		if (summary.log_file) {
			html += '<li>' + escapeHtml(ttmAdmin.i18n.logFile) + ': <code>' + escapeHtml(summary.log_file) + '</code></li>';
		}

		html += '</ul>';

		$('#ttm-report-content').html(html);
		$('#ttm-report').show();
	}

	function runPostMigrationProductCleanup(summary, onDone) {
		if (!summary || !summary.cleanup_products_pending) {
			if (summary && (summary.cleanup_deleted_terms || summary.cleanup_deleted_attributes || summary.cleanup_cleaned_products)) {
				renderCleanupReport(
					{
						deleted_terms: summary.cleanup_deleted_terms || 0,
						deleted_attributes: summary.cleanup_deleted_attributes || 0,
						cleaned_products: summary.cleanup_cleaned_products || 0,
						errors: []
					},
					false,
					ttmAdmin.i18n.cleanupCompleted
				);
			}
			onDone(summary);
			return;
		}

		resetCleanupTotals();
		if (summary.cleanup_deleted_terms) {
			cleanupTotals.deleted_terms = summary.cleanup_deleted_terms;
		}
		if (summary.cleanup_deleted_attributes) {
			cleanupTotals.deleted_attributes = summary.cleanup_deleted_attributes;
		}

		setCleanupRunning(true);
		showCleanupProgress(ttmAdmin.i18n.cleanupStepProducts, ttmAdmin.i18n.cleanupProductsRunning, summary.cleanup_product_offset || 0, summary.cleanup_product_total || 0, false);
		showNotice(ttmAdmin.i18n.cleanupProductsRunning, 'info');

		function nextBatch() {
			if (!running && !cleanupRunning) {
				return;
			}

			$.post(ttmAdmin.ajaxUrl, {
				action: 'ttm_cleanup_products_batch',
				nonce: ttmAdmin.nonce
			})
				.done(function (response) {
					if (!response.success) {
						setCleanupRunning(false);
						renderCleanupReport(cleanupTotals, true, response.data && response.data.message ? response.data.message : ttmAdmin.i18n.error);
						onDone(summary);
						return;
					}

					var data = response.data;
					if (data.summary) {
						cleanupTotals.cleaned_products = data.summary.cleanup_cleaned_products || cleanupTotals.cleaned_products;
						showCleanupProgress(
							ttmAdmin.i18n.cleanupStepProducts,
							ttmAdmin.i18n.cleanupProductsRunning,
							data.summary.cleanup_product_offset || 0,
							data.summary.cleanup_product_total || 0,
							false
						);
					}

					if (data.done && data.summary) {
						setCleanupRunning(false);
						renderCleanupReport(
							{
								deleted_terms: data.summary.cleanup_deleted_terms || 0,
								deleted_attributes: data.summary.cleanup_deleted_attributes || 0,
								cleaned_products: data.summary.cleanup_cleaned_products || 0,
								errors: []
							},
							false,
							ttmAdmin.i18n.cleanupCompleted
						);
						onDone(data.summary);
						return;
					}

					nextBatch();
				})
				.fail(function () {
					setCleanupRunning(false);
					renderCleanupReport(cleanupTotals, true, ttmAdmin.i18n.error);
					onDone(summary);
				});
		}

		nextBatch();
	}

	function runProductCleanupManual() {
		if (running || cleanupRunning) {
			showNotice(ttmAdmin.i18n.migrationBlocksCleanup, 'error');
			return;
		}

		if (!window.confirm(ttmAdmin.i18n.confirmCleanupProducts)) {
			return;
		}

		resetCleanupTotals();
		setCleanupRunning(true);
		showCleanupProgress(ttmAdmin.i18n.confirmCleanupProducts, ttmAdmin.i18n.cleanupStepProducts, 0, 0, true);

		runProductCleanupBatched(0, ttmAdmin.i18n.cleanupStepProducts, function (hadError) {
			if (hadError) {
				renderCleanupReport(cleanupTotals, true, ttmAdmin.i18n.error);
				showNotice(ttmAdmin.i18n.error, 'error');
			} else {
				renderCleanupReport(cleanupTotals, false, ttmAdmin.i18n.cleanupCompleted);
				showNotice(ttmAdmin.i18n.cleanupCompleted, 'success');
			}
			setCleanupRunning(false);
		});
	}

	function processNextBatch() {
		if (!running) {
			return;
		}

		ajaxRequest('ttm_process_batch')
			.done(function (response) {
				if (!response.success) {
					setRunning(false);
					showNotice(response.data && response.data.message ? response.data.message : ttmAdmin.i18n.error, 'error');
					return;
				}

				var data = response.data;
				updateProgress(data.state);

				if (data.completed && data.summary) {
					runPostMigrationProductCleanup(data.summary, function (finalSummary) {
						setRunning(false);
						renderReport(finalSummary);
						showNotice(ttmAdmin.i18n.completed, 'success');
					});
					return;
				}

				processNextBatch();
			})
			.fail(function () {
				setRunning(false);
				showNotice(ttmAdmin.i18n.error, 'error');
			});
	}

	$('#ttm-delete-terms').on('change', function () {
		if ($(this).is(':checked')) {
			$('.ttm-confirm-delete').show();
		} else {
			$('.ttm-confirm-delete').hide();
			$('#ttm-confirm-delete').prop('checked', false);
		}
	});

	$('#ttm-target').on('change', function () {
		var source = $('#ttm-source').val();
		var target = $(this).val();
		if (source && target && source === target) {
			showNotice(ttmAdmin.i18n.sameTaxonomy, 'error');
		}
	});

	$('#ttm-save').on('click', function () {
		var source = $('#ttm-source').val();
		var target = $('#ttm-target').val();

		if (source && target && source === target) {
			showNotice(ttmAdmin.i18n.sameTaxonomy, 'error');
			return;
		}

		var $btn = $(this);
		$btn.prop('disabled', true);

		ajaxRequest('ttm_save_settings')
			.done(function (response) {
				if (response.success) {
					showNotice(response.data && response.data.message ? response.data.message : ttmAdmin.i18n.saved, 'success');
				} else {
					showNotice(response.data && response.data.message ? response.data.message : ttmAdmin.i18n.error, 'error');
				}
			})
			.fail(function () {
				showNotice(ttmAdmin.i18n.error, 'error');
			})
			.always(function () {
				if (!running) {
					$btn.prop('disabled', false);
				}
			});
	});

	$('#ttm-preview').on('click', function () {
		if (!validateTaxonomies()) {
			return;
		}

		var $btn = $(this);
		$btn.prop('disabled', true);

		ajaxRequest('ttm_preview')
			.done(function (response) {
				if (response.success) {
					renderPreview(response.data);
				} else {
					showNotice(response.data && response.data.message ? response.data.message : ttmAdmin.i18n.error, 'error');
				}
			})
			.fail(function () {
				showNotice(ttmAdmin.i18n.error, 'error');
			})
			.always(function () {
				if (!running) {
					$btn.prop('disabled', false);
				}
			});
	});

	$('#ttm-start').on('click', function () {
		if (!validateTaxonomies()) {
			return;
		}

		if ($('#ttm-delete-terms').is(':checked') && !$('#ttm-confirm-delete').is(':checked')) {
			showNotice(ttmAdmin.i18n.confirmDelete, 'error');
			return;
		}

		if (!window.confirm(ttmAdmin.i18n.confirmStart)) {
			return;
		}

		setRunning(true);
		showNotice(ttmAdmin.i18n.migrationRunning, 'info');
		$('#ttm-report').hide();

		ajaxRequest('ttm_start_migration')
			.done(function (response) {
				if (!response.success) {
					setRunning(false);
					showNotice(response.data && response.data.message ? response.data.message : ttmAdmin.i18n.error, 'error');
					return;
				}

				updateProgress(response.data.state);
				processNextBatch();
			})
			.fail(function () {
				setRunning(false);
				showNotice(ttmAdmin.i18n.error, 'error');
			});
	});

	$('#ttm-stop').on('click', function () {
		running = false;

		ajaxRequest('ttm_stop_migration')
			.done(function (response) {
				setRunning(false);
				if (response.success) {
					showNotice(ttmAdmin.i18n.stopped, 'info');
				} else {
					showNotice(response.data && response.data.message ? response.data.message : ttmAdmin.i18n.error, 'error');
				}
			})
			.fail(function () {
				setRunning(false);
				showNotice(ttmAdmin.i18n.error, 'error');
			});
	});

	$('#ttm-delete-empty-terms').on('click', function () {
		runCleanup('ttm_delete_empty_attribute_terms', ttmAdmin.i18n.confirmDeleteEmptyTerms, ttmAdmin.i18n.cleanupStepTerms);
	});

	$('#ttm-delete-empty-attributes').on('click', function () {
		runCleanup('ttm_delete_empty_attributes', ttmAdmin.i18n.confirmDeleteEmptyAttributes, ttmAdmin.i18n.cleanupStepAttributes);
	});

	$('#ttm-cleanup-products').on('click', function () {
		runProductCleanupManual();
	});

	$('#ttm-full-cleanup').on('click', function () {
		runFullCleanupManual();
	});

	// Resume UI if migration was interrupted.
	$(function () {
		ajaxRequest('ttm_get_state')
			.done(function (response) {
				if (response.success && response.data.running) {
					setRunning(true);
					updateProgress(response.data.state);
					showNotice(ttmAdmin.i18n.migrationRunning, 'info');
					processNextBatch();
				}
			});
	});
})(jQuery);
