jQuery(document).ready(function($) {
    
    // Listen for messages from SVG-Edit iframe
    window.addEventListener('message', function(e) {
        // Only accept messages from our own domain
        if (e.origin !== window.location.origin) return;
        
        const data = e.data;
        
        // SVG-Edit sends 'ready' event when fully loaded
        if (data === 'ready' || (typeof data === 'object' && data.namespace === 'svg-edit')) {
            console.log('SVG-Edit is ready');
        }
    });
    
    // Handle Edit SVG button click
    $(document).on('click', '.ace-edit-svg', function(e) {
        e.preventDefault();
        
        const attachmentId = $(this).data('attachment-id');
        const modal = $('#ace-svg-editor-modal-' + attachmentId);
        const iframe = $('#ace-svg-editor-frame-' + attachmentId);
        
        // Show modal with loading state
        modal.show();
        
        // Load SVG content via AJAX (avoids CORS)
        $.ajax({
            url: aceSvgEditor.ajaxUrl,
            type: 'POST',
            data: {
                action: 'ace_load_svg',
                nonce: aceSvgEditor.nonce,
                attachment_id: attachmentId
            },
            success: function(response) {
                if (!response.success) {
                    alert('Error loading SVG: ' + (response.data || 'Unknown error'));
                    modal.hide();
                    return;
                }
                
                const svgContent = response.data.content;
                
                // Store data on modal BEFORE loading iframe
                modal.data('attachment-id', attachmentId);
                modal.data('svg-content', svgContent);
                modal.data('svg-loaded', false);
                
                // Load SVG-Edit
                iframe.attr('src', aceSvgEditor.svgEditUrl);
                
                // Try multiple methods to load the SVG
                let attempts = 0;
                const maxAttempts = 10;
                
                iframe.off('load').on('load', function() {
                    const checkAndLoad = function() {
                        attempts++;
                        
                        try {
                            const win = iframe[0].contentWindow;
                            
                            // Debug: Log what's available
                            if (attempts === 1) {
                                console.log('Window properties:', Object.keys(win));
                                console.log('Has svgCanvas:', !!win.svgCanvas);
                                console.log('Has svgEditor:', !!win.svgEditor);
                                console.log('Has methodDraw:', !!win.methodDraw);
                                
                                if (win.svgEditor) {
                                    console.log('svgEditor properties:', Object.keys(win.svgEditor));
                                    console.log('svgEditor.canvas:', win.svgEditor.canvas);
                                }
                            }
                            
                            // Try different methods to access SVG-Edit
                            let svgCanvas = null;
                            
                            if (win.svgCanvas) {
                                svgCanvas = win.svgCanvas;
                            } else if (win.svgEditor && win.svgEditor.svgCanvas) {
                                svgCanvas = win.svgEditor.svgCanvas;
                            } else if (win.svgEditor && win.svgEditor.canvas) {
                                svgCanvas = win.svgEditor.canvas;
                            } else if (win.methodDraw && win.methodDraw.canvas) {
                                svgCanvas = win.methodDraw.canvas;
                            } else if (win.svgEditor) {
                                // Try svgEditor directly
                                svgCanvas = win.svgEditor;
                            }
                            
                            if (svgCanvas && svgCanvas.setSvgString && !modal.data('svg-loaded')) {
                                console.log('SVG canvas found, loading content...');
                                svgCanvas.setSvgString(svgContent);
                                modal.data('svg-loaded', true);
                                return true;
                            } else if (attempts < maxAttempts) {
                                console.log('SVG canvas not ready, attempt ' + attempts);
                                setTimeout(checkAndLoad, 500);
                            } else {
                                console.error('Failed to load SVG after ' + maxAttempts + ' attempts');
                                console.log('Final window state:', {
                                    svgCanvas: !!win.svgCanvas,
                                    svgEditor: !!win.svgEditor,
                                    methodDraw: !!win.methodDraw
                                });
                                alert('SVG editor loaded but could not import your SVG. You can still use the editor to create a new SVG.');
                            }
                        } catch (error) {
                            console.error('Error accessing SVG editor:', error);
                            if (attempts < maxAttempts) {
                                setTimeout(checkAndLoad, 500);
                            }
                        }
                    };
                    
                    // Start checking after initial delay
                    setTimeout(checkAndLoad, 2000);
                });
            },
            error: function(xhr, status, error) {
                alert('Error loading SVG file: ' + error);
                modal.hide();
            }
        });
    });
    
    // Handle close button
    $(document).on('click', '.ace-svg-editor-close, .ace-svg-cancel, .ace-svg-editor-overlay', function() {
        const modal = $(this).closest('.ace-svg-editor-modal');
        closeEditor(modal);
    });
    
    // Handle save button
    $(document).on('click', '.ace-svg-save', function() {
        const modal = $(this).closest('.ace-svg-editor-modal');
        const attachmentId = modal.data('attachment-id');
        const iframe = modal.find('iframe')[0];
        
        // Request SVG data from SVG-Edit
        try {
            const svgCanvas = iframe.contentWindow.svgCanvas || iframe.contentWindow.svgEditor?.canvas;
            
            if (!svgCanvas) {
                alert('Unable to communicate with SVG editor. Please try again.');
                return;
            }
            
            // Get SVG content
            svgCanvas.getSvgString()(function(svgString) {
                saveSvg(attachmentId, svgString, modal);
            });
            
        } catch (error) {
            console.error('Error getting SVG content:', error);
            alert('Error retrieving SVG content. Please try again.');
        }
    });
    
    function saveSvg(attachmentId, svgContent, modal) {
        const saveButton = modal.find('.ace-svg-save');
        saveButton.prop('disabled', true).text('Saving...');
        
        $.ajax({
            url: aceSvgEditor.ajaxUrl,
            type: 'POST',
            data: {
                action: 'ace_save_svg',
                nonce: aceSvgEditor.nonce,
                attachment_id: attachmentId,
                svg_content: svgContent
            },
            success: function(response) {
                if (response.success) {
                    alert('SVG saved successfully!');
                    
                    // Reload the thumbnail if visible
                    const img = $('.attachment-details img[src*="' + attachmentId + '"]');
                    if (img.length) {
                        const currentSrc = img.attr('src');
                        img.attr('src', response.data.url);
                    }
                    
                    closeEditor(modal);
                } else {
                    alert('Error: ' + (response.data || 'Unknown error'));
                    saveButton.prop('disabled', false).text('Save Changes');
                }
            },
            error: function(xhr, status, error) {
                alert('AJAX Error: ' + error);
                saveButton.prop('disabled', false).text('Save Changes');
            }
        });
    }
    
    function closeEditor(modal) {
        modal.hide();
        modal.find('iframe').attr('src', '');
        modal.find('.ace-svg-save').prop('disabled', false).text('Save Changes');
    }
    
    // Close modal on ESC key
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape') {
            $('.ace-svg-editor-modal:visible').each(function() {
                closeEditor($(this));
            });
        }
    });
});
