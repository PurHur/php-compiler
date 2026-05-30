    /**
     * Lower match to === compare / jump-if / assign (issue #143).
     */
    protected function parseExpr_Match(Expr\Match_ $expr)
    {
        $attrs = $this->mapAttributes($expr);
        $endBlock = $this->block->create();
        $result = new Temporary();
        $entryBlock = $this->block;
        // Seed $result so arm blocks share one compile slot (#143).
        $entryBlock->children[] = new Op\Expr\Assign(
            $result,
            $this->readVariable(new Literal('')),
            $attrs
        );
        $cond = $this->matchPatternOperand($expr->cond, $attrs);
        // Subject/pattern lowering may advance $this->block (nested match, etc.) — #3397.
        $chainBlock = $this->block;
        $defaultArm = null;

        foreach ($expr->arms as $arm) {
            if (null === $arm->conds) {
                $defaultArm = $arm;
                continue;
            }
            $matchBlock = $this->block->create();
            $afterArmBlock = $this->block->create();
            $testBlock = $chainBlock;
            $conds = $arm->conds;
            $lastCondIdx = count($conds) - 1;
            foreach ($conds as $idx => $condNode) {
                $caseOperand = $this->matchPatternOperand($condNode, $attrs);
                // Pattern expr may finish in a different block than $testBlock started (#3397).
                $testBlock = $this->block;
                $cmp = new Op\Expr\BinaryOp\Identical(
                    $cond,
                    $caseOperand,
                    $attrs
                );
                $testBlock->children[] = $cmp;
                $nextBlock = $idx === $lastCondIdx ? $afterArmBlock : $this->block->create();
                $testBlock->children[] = new JumpIf($cmp->result, $matchBlock, $nextBlock, $attrs);
                $matchBlock->addParent($testBlock);
                $nextBlock->addParent($testBlock);
                $testBlock = $nextBlock;
            }
            $this->block = $matchBlock;
            $this->block->children[] = new Op\Expr\Assign(
                $result,
                $this->readVariable($this->parseExprNode($arm->body)),
                $attrs
            );
            $this->block->children[] = new Jump($endBlock, $attrs);
            $endBlock->addParent($this->block);
            $chainBlock = $afterArmBlock;
            $this->block = $chainBlock;
        }

        if (null !== $defaultArm) {
            $this->block->children[] = new Op\Expr\Assign(
                $result,
                $this->readVariable($this->parseExprNode($defaultArm->body)),
                $attrs
            );
            $this->block->children[] = new Jump($endBlock, $attrs);
            $endBlock->addParent($this->block);
        }

        $this->block = $endBlock;

        return $result;
    }

    /**
     * Match subject / pattern: use Literal for true/false/null (issue #2428).
     *
     * NameResolver marks bare true/false/null as qualified; avoid ConstFetch ops that break VM compares.
     *
     * @return Operand
     */
    private function matchPatternOperand($exprNode, array $attrs): Operand
    {
        if ($exprNode instanceof Expr\ConstFetch) {
            $lc = strtolower($exprNode->name->getLast());
            switch ($lc) {
                case 'true':
                    return $this->readVariable(new Literal(true));
                case 'false':
                    return $this->readVariable(new Literal(false));
                case 'null':
                    return $this->readVariable(new Literal(null));
            }
        }

        return $this->readVariable($this->parseExprNode($exprNode));
    }
