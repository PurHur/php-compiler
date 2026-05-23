<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** copy() — VM via VmFs; JIT/AOT via __compiler_copy (native fread/fwrite). */
final class copy_ extends Internal
{
    public function __construct()
    {
        parent::__construct('copy');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('copy() requires exactly two arguments in this compiler build');
        }
        $fromVar = $frame->calledArgs[0]->resolveIndirect();
        $toVar = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $fromVar->type || Variable::TYPE_STRING !== $toVar->type) {
            throw new \LogicException('copy() requires string paths in this compiler build');
        }
        $frame->returnVar->bool(VmFs::copy($fromVar->toString(), $toVar->toString()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('copy() requires exactly two arguments in this compiler build');
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type || JITVariable::TYPE_STRING !== $args[1]->type) {
            throw new \LogicException('copy() requires string paths in this compiler build');
        }

        return JitCopy::invoke(
            $context,
            $context->helper->loadValue($args[0]),
            $context->helper->loadValue($args[1])
        );
    }
}
