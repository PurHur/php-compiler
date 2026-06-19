    /**
     * Recover phpc-typed-function-static:* marker from comment attributes (#9998).
     */
    private function applyTypedFunctionStaticMarkerAttributes(Op\Terminal\StaticVar $staticOp, array $attributes): void
    {
        $payload = $this->extractTypedFunctionStaticMarkerPayloadFromAttributes($attributes);
        if (null === $payload) {
            return;
        }
        $staticOp->declaredType = $this->parseTypedFunctionStaticTypeFromMarker($payload);
    }

    /**
     * @return string|null Raw marker payload (type expression).
     */
    private function extractTypedFunctionStaticMarkerPayloadFromAttributes(array $attributes): ?string
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
            if (!preg_match(\PHPCompiler\Ast\TypedFunctionStaticRewriter::MARKER_PATTERN, $chunk, $m)) {
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
     * Parse a type expression embedded in a typed function-static marker via php-parser (#9998).
     */
    private function parseTypedFunctionStaticTypeFromMarker(string $typeExpr): Op\Type
    {
        static $parser = null;
        if (null === $parser) {
            $parser = (new \PhpParser\ParserFactory())->create(\PhpParser\ParserFactory::PREFER_PHP7);
        }
        $probe = '<?php class __PhpcTypedFunctionStaticProbe { public '.$typeExpr.' $p; }';
        try {
            $ast = $parser->parse($probe);
        } catch (\PhpParser\Error $e) {
            throw new \RuntimeException('Invalid typed function static type: '.$typeExpr, 0, $e);
        }
        if (!is_array($ast) || !isset($ast[0]) || !($ast[0] instanceof \PhpParser\Node\Stmt\Class_)) {
            throw new \RuntimeException('Invalid typed function static type probe: '.$typeExpr);
        }
        $class = $ast[0];
        if (!isset($class->stmts[0]) || !($class->stmts[0] instanceof \PhpParser\Node\Stmt\Property)) {
            throw new \RuntimeException('Invalid typed function static type probe property: '.$typeExpr);
        }

        return $this->parseTypeNode($class->stmts[0]->type);
    }
