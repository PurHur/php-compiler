    protected function parseStmt_TraitUse(Stmt\TraitUse $node)
    {
        $traits = [];
        foreach ($node->traits as $traitName) {
            $traits[] = $this->parseExprNode($traitName);
        }
        $this->block->children[] = new Op\Stmt\TraitUse(
            $traits,
            $node->adaptations,
            $this->mapAttributes($node)
        );
    }
