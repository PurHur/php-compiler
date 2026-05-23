<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** date() — format local time (subset; JIT/AOT via __compiler_format_datetime). */
final class date extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('date() requires one or two arguments');
        }
        $formatVar = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $formatVar->type) {
            throw new \LogicException('date() format must be a string in this compiler build');
        }
        $timestamp = null;
        if (2 === $argc) {
            $tsVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER === $tsVar->type) {
                $timestamp = $tsVar->toInt();
            } elseif (Variable::TYPE_NULL !== $tsVar->type) {
                throw new \LogicException('date() timestamp must be an integer or null in this compiler build');
            }
        }
        $frame->returnVar->string(VmDate::date($formatVar->toString(), $timestamp));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->jitString($context, $args[0], 'date() argument #1');
        return JitDate::formatDate($context, false, ...$args);
    }
}
