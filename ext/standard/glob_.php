<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** glob() — path pattern matching (VM via host PHP; JIT defers to VM). */
final class glob_ extends Internal
{
    public function __construct()
    {
        parent::__construct('glob');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('glob() requires one or two arguments in this compiler build');
        }
        $patternVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $patternVar->type) {
            throw new \LogicException('glob() pattern must be a string in this compiler build');
        }
        $flags = 0;
        if (2 === $argc) {
            $flagsVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $flagsVar->type) {
                throw new \LogicException('glob() flags must be an integer in this compiler build');
            }
            $flags = $flagsVar->toInt();
        }
        if (null === $frame->returnVar) {
            return;
        }

        $result = \glob($patternVar->toString(), $flags);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array(VmFs::stringListToArray($result));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('glob() requires one or two arguments in this compiler build');
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type) {
            throw new \LogicException('glob() pattern must be a string in this compiler build');
        }
        $i32 = $context->getTypeFromString('int32');
        $flags = $i32->constInt(0, false);
        if (2 === $argc) {
            $flags = $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[1], 'glob() flags'),
                $i32
            );
        }

        $pattern = $this->jitString($context, $args[0], 'glob() argument #1');

        return JitFsGlob::glob($context, $pattern, $flags);
    }
}
