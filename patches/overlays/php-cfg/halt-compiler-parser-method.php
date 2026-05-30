    protected function parseStmt_HaltCompiler(Stmt\HaltCompiler $node)
    {
        $attrs = $this->mapAttributes($node);
        $this->block->children[] = new Op\Stmt\HaltCompiler(
            $node->remaining,
            $attrs
        );
        $this->block = new Block();
        $this->block->dead = true;
    }
