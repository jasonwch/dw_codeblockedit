jQuery(function() {
    // Check if user has edit permission
    if (!JSINFO.codeblockedit_canedit) {
        return; // Don't show edit buttons if user can't edit
    }

    // Check if on a page with content (not in edit mode)
    if (document.querySelector('#dw__editform')) {
        return; // Don't add edit buttons while in edit mode
    }

    // Function to handle edit click
    var handleEdit = function(event) {
        event.preventDefault();
        event.stopPropagation();
        
        var $btn = jQuery(this);
        var index = $btn.data('index');
        var hid = 'codeblock_' + index;
        
        // Redirect to standard DokuWiki editor with codeblockindex and hid parameters
        // hid is used by DokuWiki to redirect back to this section after saving
        var url = DOKU_BASE + 'doku.php?id=' + encodeURIComponent(JSINFO.id) + '&do=edit&codeblockindex=' + index + '&hid=' + hid;
        window.location.href = url;
    };

    let sup = 'desktop';
    if (/Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent)) {
        sup = 'mobile';
    }

    var blockIndex = 0;
    
    // Process all code and file blocks - wrap the <pre> element directly like copy2clipboard does
    document.querySelectorAll('pre.code, pre.file').forEach(function(preElem) {
        // Check if already processed
        if (preElem.dataset.codeblockeditProcessed) return;
        preElem.dataset.codeblockeditProcessed = 'true';

        var currentIndex = blockIndex++;

        // Create the edit button
        var editBtn = document.createElement('button');
        editBtn.setAttribute('title', 'Edit this code block');
        editBtn.classList.add('codeblockedit-btn');
        editBtn.dataset.index = currentIndex;
        editBtn.addEventListener('click', handleEdit);

        // Check if already wrapped by copy2clipboard
        var existingWrapper = preElem.parentNode;
        var useExistingWrapper = existingWrapper && 
            existingWrapper.classList && 
            existingWrapper.classList.contains('cp2clipcont');
        
        var btnWrapper;
        if (useExistingWrapper) {
            // Reuse existing wrapper from copy2clipboard
            btnWrapper = existingWrapper;
            btnWrapper.classList.add('codeblockedit-wrapper', sup);
        } else {
            // Create our own wrapper around the <pre> element (same as copy2clipboard)
            btnWrapper = document.createElement('div');
            btnWrapper.classList.add('codeblockedit-wrapper', sup);
            
            // Wrap the pre element
            preElem.parentNode.insertBefore(btnWrapper, preElem);
            btnWrapper.appendChild(preElem);
            
            // Transfer margin from pre to wrapper for proper alignment
            var marginTop = window.getComputedStyle(preElem)['margin-top'];
            if (marginTop !== '0px') {
                btnWrapper.style['margin-top'] = marginTop;
                preElem.style['margin-top'] = '0';
            }
            var marginBottom = window.getComputedStyle(preElem)['margin-bottom'];
            if (marginBottom !== '0px') {
                btnWrapper.style['margin-bottom'] = marginBottom;
                preElem.style['margin-bottom'] = '0';
            }
        }
        
        // Add anchor ID to wrapper for redirect back after edit
        btnWrapper.id = 'codeblock_' + currentIndex;
        
        btnWrapper.appendChild(editBtn);
    });

    // After adding all IDs, check if we need to scroll to a codeblock anchor
    // This handles the case where DokuWiki redirects with #codeblock_N after save
    // but the ID didn't exist in the initial HTML (it's added by JavaScript)
    if (window.location.hash && window.location.hash.match(/^#codeblock_\d+$/)) {
        var targetId = window.location.hash.substring(1);
        var targetElement = document.getElementById(targetId);
        if (targetElement) {
            // Use setTimeout to ensure the DOM is fully ready and other plugins have run
            setTimeout(function() {
                // Expand any collapsed sections (sectiontoggle plugin compatibility)
                // Collect all hidden ancestors first, then expand from outermost to innermost
                var hiddenAncestors = [];
                var parent = targetElement.parentElement;
                
                while (parent && parent !== document.body) {
                    // Check if this element is hidden (collapsed by sectiontoggle)
                    var computedDisplay = window.getComputedStyle(parent).display;
                    if (computedDisplay === 'none' || parent.style.display === 'none') {
                        hiddenAncestors.push(parent);
                    }
                    parent = parent.parentElement;
                }
                
                // Expand from outermost (last in array) to innermost (first in array)
                // This ensures parent sections are expanded before child sections
                for (var i = hiddenAncestors.length - 1; i >= 0; i--) {
                    var hiddenElem = hiddenAncestors[i];
                    // Find the header that controls this section
                    // sectiontoggle hides the nextElementSibling of the header
                    var header = hiddenElem.previousElementSibling;
                    if (header && /^H[1-6]$/.test(header.tagName) && header.classList.contains('st_closed')) {
                        // Expand this section by toggling classes
                        header.classList.remove('st_closed');
                        header.classList.add('st_opened');
                        hiddenElem.style.display = '';
                    } else {
                        // Fallback: just show the element if we can't find the controlling header
                        hiddenElem.style.display = '';
                    }
                }
                
                // Now scroll to the target
                targetElement.scrollIntoView({ behavior: 'auto', block: 'start' });
            }, 100); // Slightly longer delay to ensure sectiontoggle has finished
        }
    }
});
