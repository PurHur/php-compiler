<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** readline_info() — readline internal state (ext/readline/readline.c; #7059). */
final class readline_info extends Internal
{
    public function __construct()
    {
        parent::__construct('readline_info');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 2) {
            throw new \ArgumentCountError('readline_info() expects at most 2 arguments, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }

        if (0 === $argc) {
            $this->writeInfoResult($frame, VmReadline::info());

            return;
        }

        $v0 = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_NULL === $v0->type) {
            $this->writeInfoResult($frame, VmReadline::info());

            return;
        }

        $varname = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'readline_info', 0, 'varname');
        if (1 === $argc) {
            $this->writeInfoResult($frame, VmReadline::info($varname));

            return;
        }

        $newvalue = self::variableToMixed($frame->calledArgs[1]->resolveIndirect());
        $result = VmReadline::info($varname, $newvalue, true);

        $this->writeInfoResult($frame, $result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 2) {
            throw new \LogicException('readline_info() expects at most 2 arguments in this compiler build');
        }
        if (0 === \count($args)) {
            return JitReadline::invokeEmptyArray($context);
        }

        return JitReadline::invokeBool($context, false);
    }

    private static function variableToMixed(Variable $var): mixed
    {
        return match ($var->type) {
            Variable::TYPE_NULL => null,
            Variable::TYPE_BOOLEAN => $var->toBool(),
            Variable::TYPE_INTEGER => $var->toInt(),
            Variable::TYPE_FLOAT => $var->toFloat(),
            Variable::TYPE_STRING => $var->toString(),
            default => $var->toString(),
        };
    }

    /**
     * @param HashTable|string|int|bool $result
     */
    private function writeInfoResult(Frame $frame, HashTable|string|int|bool $result): void
    {
        if ($result instanceof HashTable) {
            $frame->returnVar->array($result);

            return;
        }
        if (\is_bool($result)) {
            $frame->returnVar->bool($result);

            return;
        }
        if (\is_int($result)) {
            $frame->returnVar->int($result);

            return;
        }
        $frame->returnVar->string($result);
    }
}
