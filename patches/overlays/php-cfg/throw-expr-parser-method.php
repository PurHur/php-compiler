    protected function parseExpr_Throw(Expr\Throw_ $expr)
    {
        return new Op\Expr\Throw_(
            $this->readVariable($this->parseExprNode($expr->expr)),
            $this->mapAttributes($expr)
        );
    }
