jQuery(document).ready(function($) {
    'use strict';

    var $scanButton = $('#wc-rss-scan-products');
    var $scanProgress = $('#wc-rss-scan-progress');
    var $scanResults = $('#wc-rss-scan-results');
    var $generateButton = $('#wc-rss-generate-feeds');
    var $feedUrls = $('#wc-rss-feed-urls');

    // Scan products
    $scanButton.on('click', function(e) {
        e.preventDefault();

        $scanButton.prop('disabled', true).text('Scanning...');
        $scanProgress.show();

        $.ajax({
            url: wc_rss_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'wc_rss_scan_products',
                nonce: wc_rss_ajax.nonce
            },
            success: function(response) {
                $scanButton.prop('disabled', false).text('Scan Products');
                $scanProgress.hide();

                if (response.success) {
                    $('#wc-rss-product-count').text(
                        'Found ' + response.data.total_products + ' products ready for RSS feed generation.'
                    );
                    $scanResults.show();
                } else {
                    alert('Error: ' + response.data);
                }
            },
            error: function() {
                $scanButton.prop('disabled', false).text('Scan Products');
                $scanProgress.hide();
                alert('An error occurred while scanning products.');
            }
        });
    });

    // Generate feeds
    $generateButton.on('click', function(e) {
        e.preventDefault();

        $generateButton.prop('disabled', true).text('Generating...');

        $.ajax({
            url: wc_rss_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'wc_rss_generate_feeds',
                nonce: wc_rss_ajax.nonce
            },
            success: function(response) {
                $generateButton.prop('disabled', false).text('Generate RSS Feeds');

                if (response.success) {
                    // Show feed URLs
                    var feedHtml = '<h3>RSS Feed URLs</h3>';
                    feedHtml += '<p><strong>Main Product Feed:</strong></p>';
                    feedHtml += '<p><code>' + response.data.feed_url + '</code></p>';
                    feedHtml += '<p><a href="' + response.data.feed_url + '" target="_blank" class="button">View Feed</a></p>';

                    $feedUrls.html(feedHtml);

                    alert('RSS feeds generated successfully!');
                } else {
                    alert('Error: ' + response.data);
                }
            },
            error: function() {
                $generateButton.prop('disabled', false).text('Generate RSS Feeds');
                alert('An error occurred while generating feeds.');
            }
        });
    });
});
