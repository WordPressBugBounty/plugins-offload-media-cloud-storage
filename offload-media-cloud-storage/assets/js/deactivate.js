// Deactivation feedback dialog
jQuery(function ($) {
	var settings = window.acoofm_deactivation || {};
	var strings = settings.strings || {};
	var $modal = $('#acoofm-deactivate-modal');
	if (!$modal.length) {
		return;
	}

	var $dialog = $modal.find('.acoofm-dm__dialog');
	var $form = $modal.find('form');
	var $details = $modal.find('.acoofm-dm__details');
	var $comments = $details.find('textarea');
	var $error = $modal.find('.acoofm-dm__error');
	var $submit = $form.find('[type="submit"]');
	var submitLabel = $submit.text();
	var deactivateUrl = '';
	var $opener = null;
	var attempted = false;
	var busy = false;

	// The row link, or the id WordPress derives from the plugin name.
	var linkSelector =
		(settings.plugin ? 'tr[data-plugin="' + settings.plugin + '"] .deactivate a, ' : '') +
		'a#deactivate-offload-media-cloud-storage';

	$(document).on('click', linkSelector, function (e) {
		e.preventDefault();
		deactivateUrl = $(this).attr('href');
		$opener = $(this);
		open();
	});

	function open() {
		$modal.prop('hidden', false);
		$('body').addClass('acoofm-dm-open');
		// Next frame, so the opening transition runs.
		window.requestAnimationFrame(function () {
			$modal.addClass('is-open');
		});
		$form.find('input[name="Reason"]').first().trigger('focus');
		$(document).on('keydown.acoofmDm', onKeydown);
	}

	function close() {
		if (busy) {
			return;
		}
		$modal.removeClass('is-open').prop('hidden', true);
		$('body').removeClass('acoofm-dm-open');
		$(document).off('keydown.acoofmDm');
		$form[0].reset();
		$form.find('.acoofm-dm__reason').removeClass('is-selected');
		toggleDetails(false);
		attempted = false;
		$error.text('');
		if ($opener) {
			$opener.trigger('focus');
		}
	}

	function onKeydown(e) {
		if (e.key === 'Escape') {
			e.preventDefault();
			close();
			return;
		}
		if (e.key !== 'Tab') {
			return;
		}
		// Keep focus inside the dialog.
		var $focusable = $dialog
			.find('a[href], button, textarea, input:not([type="hidden"])')
			.filter(':visible:not(:disabled)');
		if (!$focusable.length) {
			return;
		}
		var first = $focusable[0];
		var last = $focusable[$focusable.length - 1];
		if (e.shiftKey && document.activeElement === first) {
			e.preventDefault();
			last.focus();
		} else if (!e.shiftKey && document.activeElement === last) {
			e.preventDefault();
			first.focus();
		} else if (!$.contains($dialog[0], document.activeElement)) {
			e.preventDefault();
			first.focus();
		}
	}

	function toggleDetails(show) {
		$details.prop('hidden', !show);
		// A disabled field is not submitted.
		$comments.prop('disabled', !show);
	}

	function selectedReason() {
		return $form.find('input[name="Reason"]:checked');
	}

	function validate() {
		var $reason = selectedReason();
		var message = '';
		if (!$reason.length) {
			message = strings.reason || 'Please select a reason.';
		} else if ($reason.is('[data-details]') && !String($comments.val() || '').trim()) {
			message = strings.details || 'Please tell us a little more.';
		}
		$error.text(attempted ? message : '');
		return message === '';
	}

	$modal.on('click', '[data-acoofm-dm-close]', function (e) {
		e.preventDefault();
		close();
	});

	$modal.on('click', '[data-acoofm-dm-skip]', function (e) {
		e.preventDefault();
		if (deactivateUrl) {
			window.location = deactivateUrl;
		}
	});

	$form.on('change', 'input[name="Reason"]', function () {
		$form.find('.acoofm-dm__reason').removeClass('is-selected');
		$(this).closest('.acoofm-dm__reason').addClass('is-selected');
		toggleDetails($(this).is('[data-details]'));
		validate();
	});

	$comments.on('input', validate);

	$form.on('submit', function (e) {
		e.preventDefault();
		attempted = true;
		if (busy || !validate()) {
			if (!selectedReason().length) {
				$form.find('input[name="Reason"]').first().trigger('focus');
			} else if (!$details.prop('hidden')) {
				$comments.trigger('focus');
			}
			return;
		}

		var data = $form.serializeArray();
		data.push(
			{ name: 'action', value: 'acoofm_deactivation_feedback' },
			{ name: 'nonce', value: settings.nonce }
		);

		busy = true;
		$dialog.attr('aria-busy', 'true');
		$form.find('button, a').addClass('is-disabled');
		$submit.prop('disabled', true).addClass('is-busy').text(strings.deactivating || 'Deactivating…');

		// Sent by the server; deactivation does not wait on the feedback service.
		$.ajax({
			type: 'post',
			url: settings.ajax_url,
			data: data,
			dataType: 'json',
			timeout: 5000,
		}).always(function () {
			window.location = deactivateUrl;
		});
	});

	// Restore the button if the page is shown again from the back/forward cache.
	$(window).on('pageshow', function () {
		busy = false;
		$dialog.removeAttr('aria-busy');
		$form.find('.is-disabled').removeClass('is-disabled');
		$submit.prop('disabled', false).removeClass('is-busy').text(submitLabel);
	});
});
