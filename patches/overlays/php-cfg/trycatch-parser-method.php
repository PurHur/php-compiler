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
            $catchTypes[] = $types;
            $catchVars[] = null !== $catch->var
                ? $this->writeVariable($this->parseExprNode($catch->var))
                : null;
        }
        $finallyBlock = null !== $node->finally ? new Block($this->block) : null;

        $this->block->children[] = new Op\Stmt\TryCatch(
            $tryBlock,
            $catchBlocks,
            $finallyBlock,
            $endBlock,
            $catchTypes,
            $catchVars,
            $attrs
        );

        $this->block->children[] = new Jump($tryBlock, $attrs);
        $tryBlock->addParent($this->block);

        $this->block = $this->parseNodes($node->stmts, $tryBlock);
        $this->block->children[] = new Jump($endBlock, $attrs);
        $endBlock->addParent($this->block);

        foreach ($node->catches as $i => $catch) {
            $this->block = $catchBlocks[$i];
            $this->block = $this->parseNodes($catch->stmts, $catchBlocks[$i]);
            $this->block->children[] = new Jump($endBlock, $attrs);
            $endBlock->addParent($this->block);
        }

        if (null !== $finallyBlock) {
            $this->block = $this->parseNodes($node->finally->stmts, $finallyBlock);
            $this->block->children[] = new Jump($endBlock, $attrs);
            $endBlock->addParent($this->block);
        }

        $this->block = $endBlock;
    }
