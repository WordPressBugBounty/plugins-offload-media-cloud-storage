// Deactivation Form
jQuery(document).ready(function () {
	jQuery(document).on('click', function (e) {
		var popup = document.getElementById('acoofm-aco-survey-form');
		var overlay = document.getElementById('acoofm-aco-survey-form-wrap');
		var openButton = document.getElementById(
			'deactivate-offload-media-cloud-storage'
		);
		if (e.target.id == 'acoofm-aco-survey-form-wrap') {
			acoofmClosePopup();
		}
		if (e.target === openButton) {
			e.preventDefault();
			popup.style.display = 'block';
			overlay.style.display = 'block';
		}
		if (e.target.id == 'acoofm-aco_skip') {
			e.preventDefault();
			var urlRedirect = document
				.querySelector('a#deactivate-offload-media-cloud-storage')
				.getAttribute('href');
			window.location = urlRedirect;
		}
		if (e.target.id == 'acoofm-aco_cancel') {
			e.preventDefault();
			acoofmClosePopup();
		}
	});

	function acoofmClosePopup() {
		var popup = document.getElementById('acoofm-aco-survey-form');
		var overlay = document.getElementById('acoofm-aco-survey-form-wrap');
		popup.style.display = 'none';
		overlay.style.display = 'none';
		jQuery('#acoofm-aco-survey-form form')[0].reset();
		jQuery('#acoofm-aco-survey-form form .acoofm-aco-comments').hide();
		jQuery('#acoofm-aco-error').html('');
	}

	jQuery('#acoofm-aco-survey-form form').on('submit', function (e) {
		e.preventDefault();
		var valid = acoofmValidate();
		if (valid) {
			var urlRedirect = document
				.querySelector('a#deactivate-offload-media-cloud-storage')
				.getAttribute('href');
			var form = jQuery(this);
			var serializeArray = form.serializeArray();
			var actionUrl = 'https://feedback.acowebs.com/plugin.php';
			jQuery.ajax({
				type: 'post',
				url: actionUrl,
				data: serializeArray,
				contentType: 'application/javascript',
				dataType: 'jsonp',
				beforeSend: function () {
					jQuery('#acoofm-aco_deactivate').prop(
						'disabled',
						'disabled'
					);
				},
				success: function (data) {
					window.location = urlRedirect;
				},
				error: function (jqXHR, textStatus, errorThrown) {
					window.location = urlRedirect;
				},
			});
		}
	});
	jQuery('#acoofm-aco-survey-form .acoofm-aco-comments textarea').on(
		'keyup',
		function () {
			acoofmValidate();
		}
	);
	jQuery("#acoofm-aco-survey-form form input[type='radio']").on(
		'change',
		function () {
			acoofmValidate();
			let val = jQuery(this).val();
			if (
				val == 'I found a bug' ||
				val == 'Plugin suddenly stopped working' ||
				val == 'Plugin broke my site' ||
				val == 'Other' ||
				val == "Plugin doesn't meets my requirement"
			) {
				jQuery(
					'#acoofm-aco-survey-form form .acoofm-aco-comments'
				).show();
			} else {
				jQuery(
					'#acoofm-aco-survey-form form .acoofm-aco-comments'
				).hide();
			}
		}
	);
	function acoofmValidate() {
		var error = '';
		var reason = jQuery(
			"#acoofm-aco-survey-form form input[name='Reason']:checked"
		).val();
		if (!reason) {
			error += 'Please select your reason for deactivation';
		}
		if (
			error === '' &&
			(reason == 'I found a bug' ||
				reason == 'Plugin suddenly stopped working' ||
				reason == 'Plugin broke my site' ||
				reason == 'Other' ||
				reason == "Plugin doesn't meets my requirement")
		) {
			var comments = jQuery(
				'#acoofm-aco-survey-form .acoofm-aco-comments textarea'
			).val();
			if (comments.length <= 0) {
				error += 'Please specify';
			}
		}
		if (error !== '') {
			jQuery('#acoofm-aco-error').html(error);
			return false;
		}
		jQuery('#acoofm-aco-error').html('');
		return true;
	}
});
