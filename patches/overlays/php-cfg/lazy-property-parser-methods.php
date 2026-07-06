    /**
     * Recover phpc-lazy-property marker from comment attributes (#16813).
     */
    private function extractLazyPropertyFromAttributes(array $attributes): bool
    {
        $chunks = [];
        if (isset($attributes['comments']) && is_array($attributes['comments'])) {
            foreach ($attributes['comments'] as $comment) {
                if (is_object($comment) && method_exists($comment, 'getText')) {
                    $chunks[] = $comment->getText();
                } elseif (is_string($comment)) {
                    $chunks[] = $comment;
                }
            }
        }
        if (isset($attributes['docComment']) && is_object($attributes['docComment'])
            && method_exists($attributes['docComment'], 'getText')) {
            $chunks[] = $attributes['docComment']->getText();
        }
        foreach ($chunks as $chunk) {
            if (preg_match(\PHPCompiler\Ast\LazyPropertyRewriter::MARKER_PATTERN, $chunk)) {
                return true;
            }
        }

        return false;
    }
