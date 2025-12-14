jQuery(document).ready(function($) {
    let images = [];
    let currentBatch = 0;
    let batchSize = 10;
    let processing = false;
    let failedImages = [];
    let totalProcessed = 0;
    
    // Load unwatermarked images
    $('#load-unwatermarked-images').on('click', function() {
        const $button = $(this);
        $button.prop('disabled', true).text('Loading...');
        
        $.ajax({
            url: wpAutoWatermark.ajaxUrl,
            type: 'POST',
            data: {
                action: 'get_unwatermarked_images',
                nonce: wpAutoWatermark.nonce
            },
            success: function(response) {
                if (response.success) {
                    images = response.data.images;
                    
                    if (images.length === 0) {
                        showStatus('No unwatermarked images found.', 'info');
                        $('#images-table-container').html('<p>All images are already watermarked!</p>');
                    } else {
                        displayImagesTable(images);
                        $('#watermark-controls').show();
                        $('#progress-total').text(images.length);
                        showStatus(`Found ${images.length} images to watermark.`, 'success');
                    }
                } else {
                    showStatus('Failed to load images.', 'error');
                }
                $button.prop('disabled', false).text('Reload Images');
            },
            error: function() {
                showStatus('Error loading images.', 'error');
                $button.prop('disabled', false).text('Retry Loading');
            }
        });
    });
    
    // Display images table
    function displayImagesTable(imagesList) {
        let html = '<table class="images-table">';
        html += '<thead><tr>';
        html += '<th>Preview</th>';
        html += '<th>Title</th>';
        html += '<th>Type</th>';
        html += '<th>Status</th>';
        html += '</tr></thead>';
        html += '<tbody>';
        
        imagesList.forEach(function(image, index) {
            html += `<tr data-image-id="${image.id}" data-index="${index}">`;
            html += `<td><img src="${image.thumb}" alt="${image.title}"></td>`;
            html += `<td>${image.title || 'Untitled'}</td>`;
            html += `<td>${image.mime}</td>`;
            html += '<td><span class="status-badge status-pending">Pending</span></td>';
            html += '</tr>';
        });
        
        html += '</tbody></table>';
        $('#images-table-container').html(html);
        $('#start-watermark').show();
    }
    
    // Start watermarking
    $('#start-watermark').on('click', function() {
        if (processing) return;
        
        processing = true;
        currentBatch = 0;
        totalProcessed = 0;
        failedImages = [];
        
        $(this).prop('disabled', true).text('Processing...');
        $('#retry-failed').hide();
        $('#progress-container').show();
        $('#results-container').hide();
        
        // Reset progress
        $('#progress-fill').css('width', '0%');
        $('#progress-current').text('0');
        $('#progress-percent').text('0');
        
        // Reset all statuses
        $('.images-table tbody tr').each(function() {
            $(this).find('.status-badge')
                .removeClass('status-success status-failed status-processing')
                .addClass('status-pending')
                .text('Pending');
        });
        
        processNextBatch();
    });
    
    // Process next batch
    function processNextBatch() {
        if (currentBatch * batchSize >= images.length) {
            // All done
            finishProcessing();
            return;
        }
        
        const start = currentBatch * batchSize;
        const end = Math.min(start + batchSize, images.length);
        const batch = images.slice(start, end);
        const batchIds = batch.map(img => img.id);
        const totalBatches = Math.ceil(images.length / batchSize);
        
        // Update status for current batch
        batch.forEach(function(image) {
            updateImageStatus(image.id, 'processing', 'Processing...');
        });
        
        // Update progress status message
        $('#progress-status').text(`Processing batch ${currentBatch + 1} of ${totalBatches}... (${batch.length} images in this batch)`);
        
        $.ajax({
            url: wpAutoWatermark.ajaxUrl,
            type: 'POST',
            data: {
                action: 'process_watermark_batch',
                nonce: wpAutoWatermark.nonce,
                image_ids: batchIds
            },
            success: function(response) {
                if (response.success) {
                    // Update success statuses
                    response.data.success.forEach(function(imageId) {
                        updateImageStatus(imageId, 'success', 'Watermarked ✓');
                        totalProcessed++;
                    });
                    
                    // Update failed statuses
                    response.data.failed.forEach(function(item) {
                        updateImageStatus(item.id, 'failed', 'Failed: ' + item.error);
                        failedImages.push(item.id);
                        totalProcessed++;
                    });
                    
                    // Update progress bar AFTER processing
                    updateProgress(totalProcessed, images.length);
                    
                    currentBatch++;
                    
                    // Continue with next batch
                    setTimeout(processNextBatch, 500);
                } else {
                    showStatus('Error processing batch.', 'error');
                    processing = false;
                    $('#start-watermark').prop('disabled', false).text('Start Watermarking');
                }
            },
            error: function() {
                // Mark all images in batch as failed
                batch.forEach(function(image) {
                    updateImageStatus(image.id, 'failed', 'Network Error');
                    failedImages.push(image.id);
                    totalProcessed++;
                });
                
                // Update progress bar
                updateProgress(totalProcessed, images.length);
                
                currentBatch++;
                
                // Continue with next batch anyway
                setTimeout(processNextBatch, 500);
            }
        });
    }
    
    // Update image status in table
    function updateImageStatus(imageId, status, text) {
        const $row = $(`.images-table tbody tr[data-image-id="${imageId}"]`);
        const $badge = $row.find('.status-badge');
        
        $badge.removeClass('status-pending status-processing status-success status-failed')
               .addClass('status-' + status)
               .text(text);
    }
    
    // Update progress bar
    function updateProgress(processed, total) {
        const percent = Math.round((processed / total) * 100);
        const successCount = processed - failedImages.length;
        const failedCount = failedImages.length;
        
        $('#progress-fill').css('width', percent + '%');
        $('#progress-current').text(processed);
        $('#progress-percent').text(percent);
        
        // Update detailed status
        const totalBatches = Math.ceil(total / batchSize);
        let statusText = `Batch ${currentBatch} of ${totalBatches} completed. `;
        statusText += `✓ ${successCount} succeeded`;
        
        if (failedCount > 0) {
            statusText += `, ✗ ${failedCount} failed`;
        }
        
        $('#progress-status').html(statusText);
    }
    
    // Finish processing
    function finishProcessing() {
        processing = false;
        $('#start-watermark').prop('disabled', false).text('Start Watermarking');
        
        const successCount = images.length - failedImages.length;
        const failedCount = failedImages.length;
        
        $('#progress-fill').css('width', '100%');
        $('#progress-current').text(images.length);
        $('#progress-percent').text('100');
        $('#progress-status').html(`<strong>✓ Processing Complete!</strong> ${successCount} succeeded, ${failedCount} failed`);
        
        // Show results
        $('#results-container').show();
        
        let summaryHtml = '<div class="notice notice-success inline">';
        summaryHtml += `<p><strong>Watermarking Complete!</strong></p>`;
        summaryHtml += `<p>✓ Successfully watermarked: <strong>${successCount}</strong> images</p>`;
        
        if (failedCount > 0) {
            summaryHtml += `<p style="color: #d63638;">✗ Failed: <strong>${failedCount}</strong> images</p>`;
        }
        
        summaryHtml += '</div>';
        $('#results-summary').html(summaryHtml);
        
        // Show failed images
        if (failedCount > 0) {
            $('#retry-failed').show();
            
            let failedHtml = '<div class="notice notice-error inline">';
            failedHtml += '<p><strong>Failed Images:</strong></p>';
            failedHtml += '<ul>';
            
            failedImages.forEach(function(imageId) {
                const image = images.find(img => img.id === imageId);
                const $row = $(`.images-table tbody tr[data-image-id="${imageId}"]`);
                const errorText = $row.find('.status-badge').text();
                
                failedHtml += `<li>${image.title || 'Untitled'} (ID: ${imageId}) - ${errorText}</li>`;
            });
            
            failedHtml += '</ul></div>';
            $('#failed-images').html(failedHtml);
        } else {
            $('#failed-images').html('');
        }
        
        showStatus(`Watermarking complete! ${successCount} succeeded, ${failedCount} failed.`, 
                   failedCount > 0 ? 'warning' : 'success');
    }
    
    // Retry failed images
    $('#retry-failed').on('click', function() {
        if (processing || failedImages.length === 0) return;
        
        // Filter images to only failed ones
        images = images.filter(img => failedImages.includes(img.id));
        
        // Reset and start again
        currentBatch = 0;
        totalProcessed = 0;
        failedImages = [];
        processing = true;
        
        $(this).hide();
        $('#start-watermark').prop('disabled', true).text('Processing...');
        $('#results-container').hide();
        $('#progress-total').text(images.length);
        
        // Reset statuses for failed images
        images.forEach(function(image) {
            updateImageStatus(image.id, 'pending', 'Pending');
        });
        
        showStatus(`Retrying ${images.length} failed images...`, 'info');
        
        processNextBatch();
    });
    
    // Show status message
    function showStatus(message, type) {
        const $status = $('#watermark-status');
        
        $status.removeClass('notice-success notice-error notice-warning notice-info')
               .addClass('notice-' + type)
               .find('p').text(message);
        
        $status.show();
        
        // Auto-hide after 5 seconds for success/info
        if (type === 'success' || type === 'info') {
            setTimeout(function() {
                $status.fadeOut();
            }, 5000);
        }
    }

    // Load watermarked images
    $('#load-watermarked-images').click(function() {
        var container = $('#watermarked-images');
        container.html('Loading...');
        $.post(wpAutoWatermark.ajaxUrl, {
            action: 'get_watermarked_images',
            nonce: wpAutoWatermark.nonce
        }, function(response) {
            if (response.success) {
                container.html(renderImages(response.data.images));
            } else {
                container.html('No images found.');
            }
        });
    });

    // Render images
    function renderImages(images) {
        if (!images.length) return 'No images found.';
        var html = '<div style="display:flex; flex-wrap:wrap; gap:10px;">';
        images.forEach(function(img) {
            html += '<a href="' + img.url + '" target="_blank">';
            html += '<img src="' + img.thumb + '" style="border:1px solid #ccc; padding:2px; max-width:100px; height:auto;" />';
            html += '</a>';
        });
        html += '</div>';
        return html;
    }

    // Tab switching
    $('.nav-tab').click(function(e){
        e.preventDefault();
        var tab = $(this).data('tab');

        $('.nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');

        $('.tab-content').hide();
        $('#tab-' + tab).show();
    });
});