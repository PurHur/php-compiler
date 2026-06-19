<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * getenv() — read process environment (VM; JIT/AOT via GetenvJitHelper / VmEnv, #3710, #5075, #9092).
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
        if ($argc > 2) {
            throw new \LogicException('getenv() accepts at most two arguments');
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (0 === $argc) {
            $frame->returnVar->array(VmEnv::getAllEnvironmentTable());

            return;
        }
        $localOnly = false;
        if (2 === $argc) {
            $localOnly = $frame->calledArgs[1]->resolveIndirect()->toBool();
        }
        $name = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'getenv', 0, 'name');
        $result = VmEnv::getenv($name, $localOnly);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 2) {
            throw new \LogicException('getenv() accepts at most two arguments');
        }
        if (0 === $argc) {
            return JitEnv::getenvAll($context);
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
            JitStringBuiltinArg::lower($context, $args[0], 'getenv', 0, 'name'),
            $localOnlyI8
        );
    }
}
