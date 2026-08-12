    /**
     * Lower match to === compare / jump-if / assign (issue #143).
     */
    protected function parseExpr_Match(Expr\Match_ $expr)
    {
        $attrs = $this->mapAttributes($expr);
        $endBlock = $this->block->create();
        $result = new Temporary();
        $entryBlock = $this->block;
        // Seed $result so arm blocks share one compile slot (#143). Use null, not '' — an empty-string
        // block constant collides with the match subject temp on shared slots (#5448).
        $entryBlock->children[] = new Op\Expr\Assign(
            $result,
            $this->readVariable(new Literal(null)),
            $attrs
        );
        $cond = $this->matchPatternOperand($expr->cond, $attrs);
        // Subject/pattern lowering may advance $this->block (nested match, etc.) — #3397.
        $chainBlock = $this->block;
        $defaultArm = null;

        foreach ($expr->arms as $arm) {
            if (null === $arm->conds) {
                if (null !== $defaultArm) {
                    throw new \CompileError('Match expressions may only contain one default arm');
                }
                $defaultArm = $arm;
                continue;
            }
            $matchBlock = $this->block->create();
            $afterArmBlock = $this->block->create();
            $testBlock = $chainBlock;
            $conds = $arm->conds;
            $lastCondIdx = count($conds) - 1;
            foreach ($conds as $idx => $condNode) {
                $patternBlock = $this->block;
                $caseOperand = $this->matchPatternOperand($condNode, $attrs);
                // Pattern expr may finish in a different block than $testBlock started (#3397).
                if ($this->block !== $patternBlock) {
                    $testBlock = $this->block;
                }
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
                $this->block = $nextBlock;
            }
            $this->block = $matchBlock;
            $this->lowerMatchArmBody($arm->body, $result, $attrs, $endBlock);
            $chainBlock = $afterArmBlock;
            $this->block = $chainBlock;
        }

        $this->block = $chainBlock;
        if (null !== $defaultArm) {
            $this->lowerMatchArmBody($defaultArm->body, $result, $attrs, $endBlock);
        } else {
            // Zend zend_throw_unhandled_match_error() when no arm matches (#4221).
            $this->lowerUnhandledMatchError($cond, $attrs);
        }

        $this->block = $endBlock;

        return $result;
    }

    /**
     * throw new UnhandledMatchError — zend_match_unhandled_error (#4221, #5448, #23664).
     *
     * Message via phpc_match_unhandled_operand_message (smart_str_append_scalar /
     * "of type …") — not (string) cast, which leaks strings and Array-warns.
     *
     * Snapshot `$cond` into a fresh temp in this block before the helper call. Cross-block
     * ARG_SEND of a named subject CV was remapped onto the null match-result seed, so
     * variable subjects always printed "Unhandled match case NULL" (#24329, re-#23664).
     *
     * php-src: Zend/zend_execute.c — zend_match_unhandled_error()
     * php-src: Zend/zend_smart_str.c — smart_str_append_scalar
     */
    private function lowerUnhandledMatchError(Operand $cond, array $attrs): void
    {
        $subject = new Temporary();
        $this->block->children[] = new Op\Expr\Assign(
            $subject,
            $this->readVariable($cond),
            $attrs
        );
        $msg = new Op\Expr\FuncCall(
            $this->readVariable(new Literal('phpc_match_unhandled_operand_message')),
            [$this->readVariable($subject)],
            $attrs
        );
        $this->block->children[] = $msg;
        $this->lowerThrowUnhandledMatchError($msg->result, $attrs);
    }

    private function lowerThrowUnhandledMatchError(Operand $msg, array $attrs): void
    {
        $class = $this->readVariable(new Literal('UnhandledMatchError'));
        $new = new Op\Expr\New_($class, [$this->readVariable($msg)], $attrs);
        $this->block->children[] = $new;
        $this->block->children[] = new Op\Terminal\Throw_(
            $this->readVariable($new->result),
            $attrs
        );
        $dead = $this->block->create();
        $dead->dead = true;
        $this->block = $dead;
    }

    /**
     * Match arm value: assign to result or throw (issue #3398).
     * Assignment arms use readVariable for the LHS so side effects bind in {main} (#3787).
     */
    private function lowerMatchArmBody($body, Temporary $result, array $attrs, Block $endBlock): void
    {
        if ($body instanceof Expr\Throw_) {
            $this->block->children[] = new Op\Terminal\Throw_(
                $this->readVariable($this->parseExprNode($body->expr)),
                $attrs
            );
            $dead = $this->block->create();
            $dead->dead = true;
            $this->block = $dead;

            return;
        }

        if ($body instanceof Expr\Assign) {
            $bodyAttrs = $this->mapAttributes($body);
            $rhs = $this->readVariable($this->parseExprNode($body->expr));
            $lhs = $this->readVariable($this->parseExprNode($body->var));
            $this->block->children[] = $inner = new Op\Expr\Assign($lhs, $rhs, $bodyAttrs);
            $this->block->children[] = new Op\Expr\Assign(
                $result,
                $this->readVariable($inner->result),
                $attrs
            );
            $this->block->children[] = new Jump($endBlock, $attrs);
            $endBlock->addParent($this->block);

            return;
        }

        $this->block->children[] = new Op\Expr\Assign(
            $result,
            $this->readVariable($this->parseExprNode($body)),
            $attrs
        );
        $this->block->children[] = new Jump($endBlock, $attrs);
        $endBlock->addParent($this->block);
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
