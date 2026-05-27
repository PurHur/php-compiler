    protected function parseStmt_Enum(Stmt\Enum_ $node)
    {
        $name = $this->parseExprNode($node->namespacedName);
        $old = $this->currentClass;
        $this->currentClass = $name;
        $backedType = null;
        if (null !== $node->scalarType) {
            $backedType = new Op\Type\Literal(
                $node->scalarType->toString(),
                $this->mapAttributes($node->scalarType)
            );
        }
        $stmtsBlock = new Block();
        $savedBlock = $this->block;
        $this->block = $stmtsBlock;
        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Stmt\EnumCase) {
                $this->parseEnumCase($stmt);
            }
        }
        $this->block = $savedBlock;
        $this->block->children[] = new Op\Stmt\Enum_(
            $name,
            $backedType,
            $stmtsBlock,
            $this->mapAttributes($node)
        );
        $this->currentClass = $old;
    }

    protected function parseEnumCase(Stmt\EnumCase $node): void
    {
        $tmp = $this->block;
        $this->block = $valueBlock = new Block();
        if (null !== $node->expr) {
            $value = $this->parseExprNode($node->expr);
        } else {
            $value = $this->readVariable(new Literal($node->name->toString()));
        }
        $this->block = $tmp;

        $this->block->children[] = new Op\Terminal\Const_(
            $this->parseExprNode($node->name),
            $value,
            $valueBlock,
            $this->mapAttributes($node)
        );
    }

