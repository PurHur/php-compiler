<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringStrnatcasecmp;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * strnatcasecmp() — case-insensitive natural-order string compare (subset of PHP; issue #2372).
 */
final class strnatcasecmp extends Internal
{
    public function __construct()
    {
        parent::__construct('strnatcasecmp');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('strnatcasecmp() requires exactly two arguments');
        }
        $a = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'strnatcasecmp', 0, 'string1');
        $b = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'strnatcasecmp', 1, 'string2');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmString::strnatcasecmp($a, $b));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (2 !== \count($args)) {
            throw new \LogicException('strnatcasecmp() requires exactly two arguments');
        }
        StringStrnatcasecmp::ensureLinked($context);
        $p0 = $this->stringDataPtr($context, JitStringBuiltinArg::lower($context, $args[0], 'strnatcasecmp', 0, 'string1'));
        $p1 = $this->stringDataPtr($context, JitStringBuiltinArg::lower($context, $args[1], 'strnatcasecmp', 1, 'string2'));
        $fn = $context->lookupFunction('strnatcasecmp');
        $raw = $context->builder->call($fn, $p0, $p1);
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->sExt($raw, $i64);
    }
}
