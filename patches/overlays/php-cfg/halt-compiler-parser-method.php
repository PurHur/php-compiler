    protected function parseStmt_HaltCompiler(Stmt\HaltCompiler $node)
    {
        $attrs = $this->mapAttributes($node);
        $haltOffset = 0;
        if ('' !== $this->sourceCode) {
            $haltOffset = strlen($this->sourceCode) - strlen($node->remaining);
        }
        $this->block->children[] = new Op\Stmt\HaltCompiler(
            $node->remaining,
            $haltOffset,
            $attrs
        );
        $this->block = new Block();
        $this->block->dead = true;
    }
