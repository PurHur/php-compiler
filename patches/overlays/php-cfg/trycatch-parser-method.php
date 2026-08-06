    protected function parseStmt_TryCatch(Stmt\TryCatch $node)
    {
        $attrs = $this->mapAttributes($node);
        $endBlock = new Block();
        $tryBlock = new Block($this->block);
        $catchBlocks = [];
        $catchTypes = [];
        $catchVars = [];
        foreach ($node->catches as $catch) {
            $catchBlocks[] = new Block($this->block);
            $types = [];
            foreach ($catch->types as $type) {
                $types[] = $type->toString();
            }
            // Intersection catch rewritten to union for php-parser; restore `&` encoding (#28205).
            if ($catch->getAttribute(\PHPCompiler\Ast\CatchIntersectionSupport::ATTRIBUTE)) {
                $catchTypes[] = [implode('&', $types)];
            } else {
                $catchTypes[] = $types;
            }
            $catchVars[] = null !== $catch->var
                ? $this->writeVariable($this->parseExprNode($catch->var))
                : null;
        }
        $finallyBlock = null !== $node->finally ? new Block($this->block) : null;
        $elseBlock = null;
        $elseSource = $node->getAttribute(\PHPCompiler\Ast\TryCatchElseSupport::ATTRIBUTE);
        if (is_string($elseSource) && '' !== $elseSource) {
            $elseBlock = new Block($this->block);
        }

        $this->block->children[] = new Op\Stmt\TryCatch(
            $tryBlock,
            $catchBlocks,
            $finallyBlock,
            $endBlock,
            $catchTypes,
            $catchVars,
            $attrs,
            $elseBlock
        );

        $this->block->children[] = new Jump($tryBlock, $attrs);
        $tryBlock->addParent($this->block);

        $this->block = $this->parseNodes($node->stmts, $tryBlock);
        $this->block->children[] = new Jump($elseBlock ?? $endBlock, $attrs);
        $endBlock->addParent($this->block);

        foreach ($node->catches as $i => $catch) {
            $this->block = $catchBlocks[$i];
            $this->block = $this->parseNodes($catch->stmts, $catchBlocks[$i]);
            $this->block->children[] = new Jump($endBlock, $attrs);
            $endBlock->addParent($this->block);
        }

        if (null !== $elseBlock) {
            $elseAst = $this->astParser->parse('<?php '.$elseSource);
            $elseStmts = [];
            foreach ($elseAst as $stmt) {
                if ($stmt instanceof Stmt\InlineHTML) {
                    continue;
                }
                $elseStmts[] = $stmt;
            }
            $this->block = $this->parseNodes($elseStmts, $elseBlock);
            $this->block->children[] = new Jump($endBlock, $attrs);
            $endBlock->addParent($this->block);
        }

        if (null !== $finallyBlock) {
            $finallyId = ++$this->ctx->gotoScopeId;
            $this->ctx->gotoFinallyStack[] = $finallyId;
            try {
                $this->block = $this->parseNodes($node->finally->stmts, $finallyBlock);
            } finally {
                array_pop($this->ctx->gotoFinallyStack);
            }
            $this->block->children[] = new Jump($endBlock, $attrs);
            $endBlock->addParent($this->block);
        }

        $this->block = $endBlock;
    }
