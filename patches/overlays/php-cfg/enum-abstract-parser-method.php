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
        $flags = 0;
        if ($node->getAttribute(\PHPCompiler\Ast\AbstractEnumMarker::ATTR)) {
            $flags = Stmt\Class_::MODIFIER_ABSTRACT;
        } elseif (property_exists($node, 'flags')) {
            $flags = (int) $node->flags;
        }
        $stmtsBlock = new Block();
        $savedBlock = $this->block;
        $this->block = $stmtsBlock;
        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Stmt\EnumCase) {
                $this->parseEnumCase($stmt);
            } elseif ($stmt instanceof Stmt\ClassMethod) {
                $this->parseStmt_ClassMethod($stmt);
            }
        }
        $this->block = $savedBlock;
        $this->block->children[] = new Op\Stmt\Enum_(
            $name,
            $backedType,
            $this->parseExprList($node->implements),
            $stmtsBlock,
            $flags,
            $this->mapAttributes($node)
        );
        $this->currentClass = $old;
    }
