    protected function parseExpr_PostDec(Expr\PostDec $expr)
    {
        $var = $this->parseExprNode($expr->var);
        $read = $this->readVariable($var);
        $write = $this->writeVariable($var);

        return new Op\Expr\PostDec($read, $write, $this->mapAttributes($expr));
    }

    protected function parseExpr_PostInc(Expr\PostInc $expr)
    {
        $var = $this->parseExprNode($expr->var);
        $read = $this->readVariable($var);
        $write = $this->writeVariable($var);

        return new Op\Expr\PostInc($read, $write, $this->mapAttributes($expr));
    }

    protected function parseExpr_PreDec(Expr\PreDec $expr)
    {
        $var = $this->parseExprNode($expr->var);
        $read = $this->readVariable($var);
        $write = $this->writeVariable($var);

        return new Op\Expr\PreDec($read, $write, $this->mapAttributes($expr));
    }

    protected function parseExpr_PreInc(Expr\PreInc $expr)
    {
        $var = $this->parseExprNode($expr->var);
        $read = $this->readVariable($var);
        $write = $this->writeVariable($var);

        return new Op\Expr\PreInc($read, $write, $this->mapAttributes($expr));
    }
