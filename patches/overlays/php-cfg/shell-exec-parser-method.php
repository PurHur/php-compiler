    protected function parseExpr_ShellExec(Expr\ShellExec $expr)
    {
        $this->block->children[] = $arg = new Op\Expr\ConcatList(
            $this->parseExprList($expr->parts, self::MODE_READ),
            $this->mapAttributes($expr)
        );

        return new Op\Expr\FuncCall(
            new Operand\Literal('shell_exec'),
            [$arg->result],
            $this->mapAttributes($expr)
        );
    }

