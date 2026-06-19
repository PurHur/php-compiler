    /**
     * Recover phpc-global-typed-const:* marker from comment attributes (#7081, #9909).
     */
    private function applyGlobalTypedConstMarkerAttributes(Op\Terminal\Const_ $constOp, array $attributes): void
    {
        $payload = $this->extractGlobalTypedConstMarkerPayloadFromAttributes($attributes);
        if (null === $payload) {
            return;
        }
        $parsed = \PHPCompiler\Ast\GlobalTypedConstRewriter::parseMarkerPayload($payload);
        if (null === $parsed) {
            return;
        }
        [$typeExpr, $isFinal] = $parsed;
        $constOp->declaredType = $this->parseGlobalTypedConstTypeFromMarker($typeExpr);
        if ($isFinal) {
            $constOp->flags |= \PhpParser\Node\Stmt\Class_::MODIFIER_FINAL;
        }
    }

    /**
     * @return string|null Raw marker payload (type or final:type).
     */
    private function extractGlobalTypedConstMarkerPayloadFromAttributes(array $attributes): ?string
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
            if (!preg_match(\PHPCompiler\Ast\GlobalTypedConstRewriter::MARKER_PATTERN, $chunk, $m)) {
                continue;
            }
            $payload = trim($m[1]);
            if ('' !== $payload) {
                return $payload;
            }
        }

        return null;
    }

    /**
     * Parse a type expression embedded in a global typed-const marker via php-parser (#7081).
     */
    private function parseGlobalTypedConstTypeFromMarker(string $typeExpr): Op\Type
    {
        static $parser = null;
        if (null === $parser) {
            $parser = (new \PhpParser\ParserFactory())->create(\PhpParser\ParserFactory::PREFER_PHP7);
        }
        $probe = '<?php class __PhpcGlobalTypedConstProbe { public '.$typeExpr.' $p; }';
        try {
            $ast = $parser->parse($probe);
        } catch (\PhpParser\Error $e) {
            throw new \RuntimeException('Invalid typed global constant type: '.$typeExpr, 0, $e);
        }
        if (!is_array($ast) || !isset($ast[0]) || !($ast[0] instanceof \PhpParser\Node\Stmt\Class_)) {
            throw new \RuntimeException('Invalid typed global constant type probe: '.$typeExpr);
        }
        $class = $ast[0];
        if (!isset($class->stmts[0]) || !($class->stmts[0] instanceof \PhpParser\Node\Stmt\Property)) {
            throw new \RuntimeException('Invalid typed global constant type probe property: '.$typeExpr);
        }

        return $this->parseTypeNode($class->stmts[0]->type);
    }
