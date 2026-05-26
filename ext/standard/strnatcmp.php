<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * strnatcmp() — natural-order string compare (subset of PHP; issue #2358).
 */
final class strnatcmp extends Internal
{
    public function __construct()
    {
        parent::__construct('strnatcmp');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('strnatcmp() requires exactly two arguments');
        }
        $a = $frame->calledArgs[0]->resolveIndirect()->toString();
        $b = $frame->calledArgs[1]->resolveIndirect()->toString();
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmString::strnatcmp($a, $b));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (2 !== \count($args)) {
            throw new \LogicException('strnatcmp() requires exactly two arguments');
        }
        $p0 = $this->stringDataPtr($context, $this->jitString($context, $args[0], 'strnatcmp() argument #1'));
        $p1 = $this->stringDataPtr($context, $this->jitString($context, $args[1], 'strnatcmp() argument #2'));
        $fn = $context->lookupFunction('strnatcmp');
        $raw = $context->builder->call($fn, $p0, $p1);
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->sExt($raw, $i64);
    }
}
