<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPCompiler\Web\Params;
use PHPLLVM\Value;

/**
 * web_int() — coerce a query/body array value to int with optional bounds (issue #157).
 */
final class web_int extends Internal
{
    public function __construct()
    {
        parent::__construct('web_int');
    }

    public function execute(Frame $frame): void
    {
        $argc = count($frame->calledArgs);
        if ($argc < 3 || $argc > 5) {
            throw new \LogicException('web_int() requires three to five arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $source = $frame->calledArgs[0]->resolveIndirect();
        $keyVar = $frame->calledArgs[1]->resolveIndirect();
        $defaultVar = $frame->calledArgs[2]->resolveIndirect();
        if (Variable::TYPE_STRING !== $keyVar->type || Variable::TYPE_INTEGER !== $defaultVar->type) {
            throw new \LogicException(
                'web_int() requires (array, string key, int default) in this compiler build'
            );
        }
        $min = null;
        $max = null;
        if ($argc >= 4) {
            $minVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $minVar->type) {
                throw new \LogicException('web_int() min must be an integer in this compiler build');
            }
            $min = $minVar->toInt();
        }
        if ($argc >= 5) {
            $maxVar = $frame->calledArgs[4]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $maxVar->type) {
                throw new \LogicException('web_int() max must be an integer in this compiler build');
            }
            $max = $maxVar->toInt();
        }
        $frame->returnVar->int(
            Params::coerceInt($source, $keyVar->toString(), $defaultVar->toInt(), $min, $max)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->jitString($context, $args[1], 'web_int() key');
        JitLongArg::lower($context, $args[2], 'web_int() default');

        return JitWebParams::webInt($context, ...$args);
    }
}
