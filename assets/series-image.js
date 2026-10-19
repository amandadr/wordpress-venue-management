(function ($) {
	var frame;

	function bind(button, clear) {
		$(button).on('click', function (event) {
			event.preventDefault();
			if (frame) {
				frame.open();
				return;
			}
			frame = wp.media({
				title: 'Series image',
				multiple: false,
				library: { type: 'image' }
			});
			frame.on('select', function () {
				var attachment = frame.state().get('selection').first().toJSON();
				$('#wvm_series_image_id').val(attachment.id);
				$('#wvm-series-image-preview').attr('src', attachment.url).show();
			});
			frame.open();
		});

		$(clear).on('click', function (event) {
			event.preventDefault();
			$('#wvm_series_image_id').val('');
			$('#wvm-series-image-preview').attr('src', '').hide();
		});
	}

	$(function () {
		bind('#wvm-series-image', '#wvm-series-image-clear');
	});
}(jQuery));
