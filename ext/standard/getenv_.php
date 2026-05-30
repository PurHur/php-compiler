<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * getenv() — read process environment (VM; JIT/AOT via __compiler_getenv, issue #3710).
 *
 * php-src: ext/standard/basic_functions.c — zif_getenv
 */
final class getenv_ extends Internal
{
    public function __construct()
    {
        parent::__construct('getenv');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('getenv() requires one or two arguments');
        }
        $localOnly = false;
        if (2 === $argc) {
            $localOnly = $frame->calledArgs[1]->resolveIndirect()->toBool();
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('getenv() requires a string name in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmEnv::getenv($v->toString(), $localOnly);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('getenv() requires one or two arguments');
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type) {
            throw new \LogicException('getenv() requires a string name in this compiler build');
        }
        $i8 = $context->getTypeFromString('int8');
        $localOnlyI8 = $i8->constInt(0, false);
        if (2 === $argc) {
            $localOnlyI8 = $context->builder->zExt(
                JitBoolArg::lower($context, $args[1], 'getenv() local_only'),
                $i8
            );
        }

        return JitEnv::getenv(
            $context,
            $this->jitString($context, $args[0], 'getenv() name'),
            $localOnlyI8
        );
    }
}
