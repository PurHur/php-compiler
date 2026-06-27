<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\ArrayPadRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * array_pad() for packed list arrays (subset of PHP; JIT via ArrayPadRuntime PHP bridge).
 */
final class array_pad extends Internal
{
    public function __construct()
    {
        parent::__construct('array_pad');
    }

    public function execute(Frame $frame): void
    {
        if (3 !== \count($frame->calledArgs)) {
            throw new \LogicException('array_pad() requires exactly three arguments');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ht = VmArray::requireArrayParam($frame->calledArgs[0], 'array_pad', 1, 'array');
        $length = $frame->calledArgs[1]->resolveIndirect();
        $value = $frame->calledArgs[2]->resolveIndirect();
        $lengthInt = VmMath::parseIntBuiltinArg($length, 'array_pad', 2, 'length');
        $frame->returnVar->array(
            VmArray::pad($ht, $lengthInt, $value)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (3 !== \count($args)) {
            throw new \LogicException('array_pad() requires exactly three arguments');
        }
        TypeErrorRaise::ensureLinked($context);
        JitArrayElem::requireArrayParam($context, $args[0], 'array_pad', 1, 'array');
        foreach ($args as $i => $arg) {
            if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
                $this->jitString($context, $arg, 'array_pad() argument #'.((int) $i + 1));
            }
        }
        $length = JitIntdiv::lowerIntBuiltinArg($context, $args[1], 'array_pad', 2, 'length');

        return ArrayPadRuntime::pad($context, $args[0], $length, $args[2]);
    }
}
