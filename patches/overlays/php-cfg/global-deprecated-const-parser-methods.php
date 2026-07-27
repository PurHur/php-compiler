    /**
     * Recover phpc-global-deprecated-const:* / phpc-global-const-attrs:* markers (#16819, #23882).
     */
    private function applyGlobalDeprecatedConstMarkerAttributes(Op\Terminal\Const_ $constOp, array $attributes): void
    {
        $chunks = $this->globalConstMarkerCommentChunks($attributes);
        foreach ($chunks as $chunk) {
            if (preg_match(\PHPCompiler\Ast\GlobalDeprecatedConstRewriter::ATTRS_MARKER_PATTERN, $chunk, $m)) {
                $groups = \PHPCompiler\Ast\GlobalDeprecatedConstRewriter::parseAttrsMarkerPayload($m[1]);
                if ([] !== $groups) {
                    $constOp->setAttribute('attrGroups', $groups);
                }
            }
            if (preg_match(\PHPCompiler\Ast\GlobalDeprecatedConstRewriter::MARKER_PATTERN, $chunk, $m)) {
                $payload = trim($m[1]);
                if ('' === $payload) {
                    continue;
                }
                $meta = \PHPCompiler\Ast\GlobalDeprecatedConstRewriter::parseMarkerPayload($payload);
                if (null !== $meta) {
                    $constOp->setAttribute('phpcGlobalDeprecatedMetadata', $meta);
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private function globalConstMarkerCommentChunks(array $attributes): array
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

        return $chunks;
    }

    /**
     * Recover phpc-global-deprecated-const:* marker from comment attributes (#16819).
     *
     * @return string|null Raw marker payload (message=…|since=…).
     */
    private function extractGlobalDeprecatedConstMarkerPayloadFromAttributes(array $attributes): ?string
    {
        foreach ($this->globalConstMarkerCommentChunks($attributes) as $chunk) {
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
