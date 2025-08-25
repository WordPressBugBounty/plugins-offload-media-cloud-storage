jQuery(function ($) {
	const media = wp.media;
	const globalData = window.acoofm_media_object;
	if (media) {
		const MediaView = media.view;
		const Button = MediaView.Button;
		const AttachmentDetailColumn = MediaView.Attachment.Details.TwoColumn;

		if (AttachmentDetailColumn) {
			MediaView.Attachment.Details.TwoColumn =
				AttachmentDetailColumn.extend({
					render: function () {
						this.fetchProviderDetails(this.model.get('id'));
					},

					fetchProviderDetails: function (id) {
						wp.ajax
							.send('acoofm_get_attachment_details', {
								data: {
									_nonce: globalData.file_details_nonce,
									id: id,
								},
							})
							.done(_.bind(this.renderView, this));
					},

					renderView: function (response) {
						// Render parent media.view.Attachment.Details
						AttachmentDetailColumn.prototype.render.apply(this);

						this.renderServerDetails(response);
					},

					renderServerDetails: function (response) {
						if (response.status) {
							var $detailsHtml = this.$el.find('.details');
							var data = response.data;
							var append = [];
							if (data.provider) {
								var providerHtml =
									"<div class='acoofm_provider'><strong>" +
										window.acoofm_media_object.strings
											.provider +
										'</strong>' +
										data.provider.label ||
									data.provider.slug + '</div>';
								append.push(providerHtml);
							}
							if (data.region) {
								var regionHtml =
									"<div class='acoofm_region'><strong>" +
									window.acoofm_media_object.strings.region +
									'</strong>' +
									data.region +
									'</div>';
								append.push(regionHtml);
							}

							var accessString = data.private
								? window.acoofm_media_object.strings
										.access_private
								: window.acoofm_media_object.strings
										.access_public;
							var accessHtml =
								"<div class='acoofm_access'><strong>" +
								window.acoofm_media_object.strings.access +
								'</strong>' +
								accessString +
								'</div>';

							append.push(accessHtml);

							if (append.length) {
								append.forEach((element, key) => {
									$detailsHtml.append(element);
								});
							}
						}
					},
				});
		}
	}
});
