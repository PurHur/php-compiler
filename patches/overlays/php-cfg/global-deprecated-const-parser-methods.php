    /**
     * Recover phpc-global-deprecated-const:* marker from comment attributes (#16819).
     */
    private function applyGlobalDeprecatedConstMarkerAttributes(Op\Terminal\Const_ $constOp, array $attributes): void
    {
        $payload = $this->extractGlobalDeprecatedConstMarkerPayloadFromAttributes($attributes);
        if (null === $payload) {
            return;
        }
        $meta = \PHPCompiler\Ast\GlobalDeprecatedConstRewriter::parseMarkerPayload($payload);
        if (null === $meta) {
            return;
        }
        $constOp->setAttribute('phpcGlobalDeprecatedMetadata', $meta);
    }

    /**
     * @return string|null Raw marker payload (message=…|since=…).
     */
    private function extractGlobalDeprecatedConstMarkerPayloadFromAttributes(array $attributes): ?string
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
            if (!preg_match(\PHPCompiler\Ast\GlobalDeprecatedConstRewriter::MARKER_PATTERN, $chunk, $m)) {
                continue;
            }
            $payload = trim($m[1]);
            if ('' !== $payload) {
                return $payload;
            }
        }

        return null;
    }
