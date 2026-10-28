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
						// Render the standard details right away; a failed
						// request must not leave the pane empty.
						AttachmentDetailColumn.prototype.render.apply(
							this,
							arguments
						);
						this.fetchProviderDetails(this.model.get('id'));
						return this;
					},

					fetchProviderDetails: function (id) {
						wp.ajax
							.send('acoofm_get_attachment_details', {
								data: {
									_nonce: globalData.file_details_nonce,
									id: id,
								},
							})
							.done(_.bind(this.renderServerDetails, this));
					},

					// One "<strong>label</strong>value" row, as text (no HTML from the response).
					detailRow: function (className, label, value) {
						return $('<div>')
							.addClass(className)
							.append($('<strong>').text(label))
							.append(document.createTextNode(value));
					},

					renderServerDetails: function (response) {
						if (!response || !response.status) {
							return;
						}
						var strings = window.acoofm_media_object.strings;
						var $detailsHtml = this.$el.find('.details');
						var data = response.data;

						// Remove rows of an earlier response before adding new ones.
						$detailsHtml
							.find('.acoofm_provider, .acoofm_region, .acoofm_access')
							.remove();

						if (data.provider) {
							$detailsHtml.append(
								this.detailRow(
									'acoofm_provider',
									strings.provider,
									data.provider.label || data.provider.slug
								)
							);
						}
						if (data.region) {
							$detailsHtml.append(
								this.detailRow(
									'acoofm_region',
									strings.region,
									data.region
								)
							);
						}
						$detailsHtml.append(
							this.detailRow(
								'acoofm_access',
								strings.access,
								data.private
									? strings.access_private
									: strings.access_public
							)
						);
					},
				});
		}
	}
});
