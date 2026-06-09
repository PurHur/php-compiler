    protected function processAssertions(Operand $op, Block $if, Block $else)
    {
        $block = $this->block;
        foreach ($op->assertions as $assert) {
            $this->block = $if;
            array_unshift($this->block->children, new Op\Expr\Assertion(
                $this->readVariable($assert['var']),
                $this->writeVariable($assert['var']),
                $this->readAssertion($assert['assertion'])
            ));
            $this->block = $else;
            array_unshift($this->block->children, new Op\Expr\Assertion(
                $this->readVariable($assert['var']),
                $this->writeVariable($assert['var']),
                new Assertion\NegatedAssertion([$this->readAssertion($assert['assertion'])])
            ));
        }
        $this->block = $block;
    }

