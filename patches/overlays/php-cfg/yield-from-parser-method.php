    protected function parseExpr_YieldFrom(Expr\YieldFrom $expr)
    {
        $inner = $this->readVariable($this->parseExprNode($expr->expr));

        return new Op\Expr\YieldFrom($inner, $this->mapAttributes($expr));
    }

