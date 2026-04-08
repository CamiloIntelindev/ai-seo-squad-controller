jQuery(function ($) {
	function escapeHtml(value) {
		return $('<div>').text(value || '').html();
	}

	function renderBrokenLinksHtml(brokenLinks) {
		if (!Array.isArray(brokenLinks) || !brokenLinks.length) {
			return '<p class="ais-no-issues">' + escapeHtml(aisAjax.i18n.noBrokenLinks) + '</p>';
		}

		var items = brokenLinks.map(function (item) {
			var url = escapeHtml(item.url || '');
			var issueType = escapeHtml(item.issue_type || 'unknown');
			var reason = escapeHtml(item.reason || 'Unknown issue');
			return '<li><strong>' + issueType + '</strong>: <a href="' + url + '" target="_blank" rel="noopener noreferrer">' + url + '</a> <span class="ais-broken-reason">(' + reason + ')</span></li>';
		});

		return '<ul class="ais-broken-links-list">' + items.join('') + '</ul>';
	}

	function renderScoreBadge(score, action) {
		var html = '';
		if (score > 0) {
			var level = score >= 80 ? 'high' : (score >= 60 ? 'medium' : 'low');
			html += '<span class="ais-badge ais-score-badge ais-score-' + level + '">' + escapeHtml(String(score)) + '/100</span> ';
		}
		if (action) {
			html += '<span class="ais-badge ais-action-badge ais-action-' + escapeHtml(action) + '">' + escapeHtml(action.toUpperCase()) + '</span>';
		}
		return html;
	}

	function renderWarningsHtml(warnings) {
		if (!Array.isArray(warnings) || !warnings.length) {
			return '';
		}
		var items = warnings.map(function (w) {
			return '<li>' + escapeHtml(w) + '</li>';
		});
		return '<ul class="ais-warnings-list">' + items.join('') + '</ul>';
	}

	function renderAuditTable(rows) {
		if (!Array.isArray(rows) || !rows.length) {
			return '<p class="ais-no-issues">Run Audit to generate technical extraction table.</p>';
		}

		var header = '<thead><tr><th>Element</th><th>Extracted Value</th><th>Status</th><th>Technical Observation</th></tr></thead>';
		var bodyRows = rows.map(function (row) {
			var element = escapeHtml((row && row.element) || '-');
			var value = escapeHtml((row && row.value) || '-');
			var statusRaw = ((row && row.status) || 'warning').toLowerCase();
			var status = (statusRaw === 'success' || statusRaw === 'error') ? statusRaw : 'warning';
			var label = status === 'success' ? 'Success' : (status === 'error' ? 'Error' : 'Warning');
			var observation = escapeHtml((row && row.observation) || '');
			return '<tr>' +
				'<td><strong>' + element + '</strong></td>' +
				'<td>' + value + '</td>' +
				'<td><span class="ais-inline-status ais-status-' + status + '">' + label + '</span></td>' +
				'<td>' + observation + '</td>' +
				'</tr>';
		});

		return '<table class="ais-audit-table">' + header + '<tbody>' + bodyRows.join('') + '</tbody></table>';
	}

	function updateCriticalState($row, hasCriticalIssues) {
		var $summary = $row.find('.ais-tech-summary');
		var $applyButton = $row.find('.ais-apply-btn');
		var hasMetaSuggestion = !!(($row.find('.ais-meta-hidden').text() || '').trim().length);
		$row.attr('data-has-critical', hasCriticalIssues ? '1' : '0');

		$row.find('.ais-badge-critical').remove();
		if (hasCriticalIssues) {
			$summary.before('<p><span class="ais-badge ais-badge-critical">' + escapeHtml(aisAjax.i18n.critical) + '</span></p>');
		}

		$applyButton.prop('disabled', hasCriticalIssues || !hasMetaSuggestion);
	}

	function setButtonLoading($button, loadingText) {
		$button.data('original-text', $button.text());
		$button.prop('disabled', true).text(loadingText);
	}

	function resetButton($button) {
		var originalText = $button.data('original-text');
		$button.prop('disabled', false).text(originalText || '');
	}

	function extractAjaxError(jqXHR) {
		var responseText = (jqXHR && jqXHR.responseText) ? String(jqXHR.responseText) : '';
		if (!responseText) {
			return aisAjax.i18n.errorGeneric;
		}

		try {
			var parsed = JSON.parse(responseText);
			if (parsed && parsed.data && parsed.data.message) {
				return parsed.data.message;
			}
		} catch (e) {
			// Fall through to plain text snippet.
		}

		return responseText.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300) || aisAjax.i18n.errorGeneric;
	}

	$(document).on('click', '.ais-audit-btn', function () {
		var $button = $(this);
		var $row = $button.closest('tr');
		var postId = $row.data('post-id');

		setButtonLoading($button, aisAjax.i18n.auditing);

		$.post(aisAjax.ajaxUrl, {
			action: 'ais_run_audit',
			nonce: aisAjax.nonce,
			post_id: postId
		})
			.done(function (response) {
				if (!response.success) {
					alert((response.data && response.data.message) ? response.data.message : aisAjax.i18n.errorGeneric);
					return;
				}

				var d = response.data;
				$row.find('.ais-meta-hidden').text(d.meta_description || '');
				$row.find('.ais-audit-table-wrap').html(renderAuditTable(d.audit_table || []));
				$row.find('.ais-tech-summary').text(d.technical_fix || '');
				$row.find('.ais-score-wrap').html(renderScoreBadge(d.confidence_score, d.recommended_action));
				$row.find('.ais-claude-summary').text(d.claude_summary || '');
				$row.find('.ais-warnings-wrap').html(renderWarningsHtml(d.claude_warnings || []));
				$row.find('.ais-broken-links-wrap').html(renderBrokenLinksHtml(d.broken_links || []));
				$row.find('.ais-last-update').text(d.last_update || '—');
				updateCriticalState($row, !!d.has_critical_issues);
			})
			.fail(function (jqXHR) {
				alert(extractAjaxError(jqXHR));
			})
			.always(function () {
				resetButton($button);
				$button.text(aisAjax.i18n.audit);
			});
	});

	$(document).on('click', '.ais-apply-btn', function () {
		var $button = $(this);
		var $row = $button.closest('tr');
		var postId = $row.data('post-id');

		setButtonLoading($button, aisAjax.i18n.applying);

		$.post(aisAjax.ajaxUrl, {
			action: 'ais_apply_suggestion',
			nonce: aisAjax.nonce,
			post_id: postId
		})
			.done(function (response) {
				if (!response.success) {
					alert((response.data && response.data.message) ? response.data.message : aisAjax.i18n.errorGeneric);
					return;
				}

				$row.find('.ais-meta-hidden').text('');
				$row.find('.ais-audit-table-wrap').html(renderAuditTable([]));
				$row.find('.ais-tech-summary').text('');
				$row.find('.ais-score-wrap').html('');
				$row.find('.ais-claude-summary').text('');
				$row.find('.ais-warnings-wrap').html('');
				$row.find('.ais-broken-links-wrap').html(renderBrokenLinksHtml([]));
				$row.find('.ais-last-update').text('—');
				updateCriticalState($row, false);
				$button.prop('disabled', true);
			})
			.fail(function (jqXHR) {
				alert(extractAjaxError(jqXHR));
			})
			.always(function () {
				resetButton($button);
				$button.text(aisAjax.i18n.apply);
			});
	});
});
