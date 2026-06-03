    /**
     * Recover phpc-asymmetric-set:* marker from comment attributes (#3165, #4690).
     */
    private function extractAsymmetricSetVisibilityFromAttributes(array $attributes): int
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
            if (!preg_match('/\/\*\s*phpc-asymmetric-set:(public|protected|private)\s*\*\//i', $chunk, $m)) {
                continue;
            }

            return match (strtolower($m[1])) {
                'public' => \PHPCfg\Func::FLAG_PUBLIC,
                'protected' => \PHPCfg\Func::FLAG_PROTECTED,
                'private' => \PHPCfg\Func::FLAG_PRIVATE,
                default => 0,
            };
        }

        return 0;
    }
