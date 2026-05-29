    protected function parseExpr_Yield(Expr\Yield_ $expr)
    {
        $key = null;
        $value = null;
        if ($expr->key) {
            $key = $this->readVariable($this->parseExprNode($expr->key));
        }
        if ($expr->value) {
            $value = $this->readVariable($this->parseExprNode($expr->value));
        }

        return new Op\Expr\Yield_($value, $key, $this->mapAttributes($expr));
    }

