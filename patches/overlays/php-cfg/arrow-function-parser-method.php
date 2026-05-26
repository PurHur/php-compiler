    protected function parseExpr_ArrowFunction(Expr\ArrowFunction $expr)
    {
        $flags = Func::FLAG_CLOSURE;
        $flags |= $expr->byRef ? Func::FLAG_RETURNS_REF : 0;
        $flags |= $expr->static ? Func::FLAG_STATIC : 0;

        $this->script->functions[] = $func = new Func(
            '{anonymous}#'.++$this->anonId,
            $flags,
            $this->parseTypeNode($expr->returnType),
            null
        );
        $this->parseFunc($func, $expr->params, $expr->getStmts(), null);

        $arrow = new Op\Expr\ArrowFunction($func, $this->mapAttributes($expr));
        $func->callableOp = $arrow;

        return $arrow;
    }
