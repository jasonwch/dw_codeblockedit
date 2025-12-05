<?php

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Form\Form;

/**
 * Class action_plugin_codeblockedit
 *
 * Handles editing of code blocks via standard DokuWiki editor
 * Uses the same range-based approach as DokuWiki's section editing
 */
class action_plugin_codeblockedit extends ActionPlugin
{
    /** @var array|null Cached block info to avoid duplicate rawWiki calls */
    protected $cachedBlock = null;
    
    /** @var int Cached block index */
    protected $cachedIndex = -1;

    /**
     * Register handlers
     *
     * @param Doku_Event_Handler $controller
     */
    public function register(Doku_Event_Handler $controller)
    {
        // Set up range before Edit action processes it
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handlePreprocess', null, 1);
        // Add hidden field to form
        $controller->register_hook('EDIT_FORM_ADDTEXTAREA', 'BEFORE', $this, 'handleAddTextarea');
        // Inject edit permission info into JSINFO
        $controller->register_hook('DOKUWIKI_STARTED', 'AFTER', $this, 'addJsInfo');
        // Handle preview rendering
        $controller->register_hook('TPL_ACT_RENDER', 'BEFORE', $this, 'handlePreview');
    }

    /**
     * Add edit permission info to JSINFO for JavaScript
     *
     * @param Doku_Event $event
     * @param mixed $param
     */
    public function addJsInfo(Doku_Event $event, $param)
    {
        global $JSINFO, $INFO;
        
        // Let JavaScript know if user can edit this page
        $JSINFO['codeblockedit_canedit'] = !empty($INFO['writable']);
    }

    /**
     * Handle preview - wrap $TEXT with code/file tags for proper rendering
     *
     * @param Doku_Event $event
     * @param mixed $param
     */
    public function handlePreview(Doku_Event $event, $param)
    {
        global $INPUT, $TEXT, $ID;

        // Only handle preview action when editing a code block
        if ($event->data !== 'preview' || !$INPUT->has('codeblockindex')) {
            return;
        }

        // Get the block info to find the tags (use cache if available)
        $index = $INPUT->int('codeblockindex');
        if ($index < 0) {
            return;
        }
        
        $block = $this->getBlockInfo($ID, $index);
        if ($block && !empty($block['openTag']) && !empty($block['closeTag'])) {
            // Wrap the TEXT with the original tags for proper preview rendering
            $TEXT = $block['openTag'] . $TEXT . $block['closeTag'];
        }
    }

    /**
     * Handle ACTION_ACT_PREPROCESS
     * 
     * Sets up $RANGE based on codeblockindex so DokuWiki's native
     * section editing mechanism handles the rest
     *
     * @param Doku_Event $event
     * @param mixed $param
     */
    public function handlePreprocess(Doku_Event $event, $param)
    {
        global $INPUT, $ID, $RANGE;

        // Only run if codeblockindex is present
        if (!$INPUT->has('codeblockindex')) {
            return;
        }

        $act = $event->data;
        if (is_array($act)) {
            $act = key($act);
        }

        // Only process for edit action when no range is set yet
        if ($act === 'edit' && empty($RANGE)) {
            // Validate index - must be non-negative integer
            $index = $INPUT->int('codeblockindex');
            if ($index < 0) {
                msg('Invalid code block index.', -1);
                return;
            }
            
            $block = $this->getBlockInfo($ID, $index);

            if ($block) {
                // Set the RANGE global - DokuWiki will use this to slice the content
                // Range is 1-based and inclusive on both ends
                // DokuWiki's rawWikiSlices() subtracts 1 from both start and end
                // So we need: start+1 for 1-based, end+1 to make end inclusive
                $RANGE = ($block['start'] + 1) . '-' . ($block['end'] + 1);
            } else {
                msg('Code block not found.', -1);
            }
        }
    }

    /**
     * Handle EDIT_FORM_ADDTEXTAREA
     *
     * Adds the hidden codeblockindex and hid fields to the form
     *
     * @param Doku_Event $event
     * @param mixed $param
     */
    public function handleAddTextarea(Doku_Event $event, $param)
    {
        global $INPUT;

        if ($INPUT->has('codeblockindex')) {
            /** @var Form $form */
            $form = $event->data['form'];
            $form->setHiddenField('codeblockindex', $INPUT->int('codeblockindex'));
            
            // Pass hid through for redirect back to code block after save
            // Sanitize hid to only allow valid anchor format (codeblock_N)
            if ($INPUT->has('hid')) {
                $hid = $INPUT->str('hid');
                if (preg_match('/^codeblock_\d+$/', $hid)) {
                    $form->setHiddenField('hid', $hid);
                }
            }
        }
    }

    /**
     * Get block info with caching to avoid duplicate rawWiki calls
     *
     * @param string $id Page ID
     * @param int $index Block index (0-based)
     * @return array|null Block info or null if not found
     */
    protected function getBlockInfo($id, $index)
    {
        // Return cached result if available
        if ($this->cachedIndex === $index && $this->cachedBlock !== null) {
            return $this->cachedBlock;
        }
        
        $text = rawWiki($id);
        if (empty($text)) {
            return null;
        }
        
        $this->cachedIndex = $index;
        $this->cachedBlock = $this->findBlockRange($text, $index);
        return $this->cachedBlock;
    }

    /**
     * Find the N-th code/file block and return its byte range
     *
     * @param string $text Raw wiki text
     * @param int $index Target index (0-based)
     * @return array|null ['start' => int, 'end' => int, 'content' => string, 'openTag' => string, 'closeTag' => string]
     */
    protected function findBlockRange($text, $index)
    {
        // Normalize line endings for consistent matching
        $text = str_replace("\r\n", "\n", $text);
        $text = str_replace("\r", "\n", $text);
        
        // Find all code/file blocks using regex
        // Pattern matches <code ...> or <file ...> blocks
        $pattern = '/(<(?:code|file)[^>]*>)(.*?)(<\/(?:code|file)>)/s';
        
        if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
            if (isset($matches[2][$index])) {
                // $matches[2][$index][0] = content
                // $matches[2][$index][1] = offset of content start
                $content = $matches[2][$index][0];
                $contentStart = $matches[2][$index][1];
                $contentEnd = $contentStart + strlen($content);
                
                // Also capture opening and closing tags for preview rendering
                $openTag = $matches[1][$index][0];
                $closeTag = $matches[3][$index][0];
                
                return [
                    'start' => $contentStart,
                    'end' => $contentEnd,
                    'content' => $content,
                    'openTag' => $openTag,
                    'closeTag' => $closeTag
                ];
            }
        }

        return null;
    }
}

