<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** gmdate() — format UTC time (subset; JIT/AOT via __compiler_format_datetime). */
final class gmdate extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('gmdate() requires one or two arguments');
        }
        $formatVar = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $formatVar->type) {
            throw new \LogicException('gmdate() format must be a string in this compiler build');
        }
        $timestamp = null;
        if (2 === $argc) {
            $timestamp = VmDate::coerceNullableTimestampArg($frame->calledArgs[1], 'gmdate', 2, 'timestamp');
        }
        $frame->returnVar->string(VmDate::gmdate($formatVar->toString(), $timestamp));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->jitString($context, $args[0], 'gmdate() argument #1');
        return \call_user_func_array([JitDate::class, 'formatDate'], array_merge([$context, true], $args));
    }
}
