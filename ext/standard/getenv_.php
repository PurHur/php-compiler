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
 * Z_PARAM_STR_OR_NULL name: coerce scalars when caller is not strict_types (#4177); TypeError under strict (#17765).
 * Named local_only without name + Reflection ?string/bool defaults (#24855).
 */
final class getenv_ extends Internal
{
    public function __construct()
    {
        parent::__construct('getenv');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/basic_functions.c — ArgumentCountError (#28690).
        $this->requireAtMostArgCount($frame, 'getenv', 2);
        if (null === $frame->returnVar) {
            return;
        }
        // Named local_only: without name leaves calledArgs[0] unset (#24855).
        $localOnly = false;
        if (isset($frame->calledArgs[1])) {
            $localOnly = $frame->calledArgs[1]->resolveIndirect()->toBool();
        }
        if (!isset($frame->calledArgs[0])) {
            $frame->returnVar->array(VmEnv::getAllEnvironmentTable());

            return;
        }
        $arg0 = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_NULL === $arg0->type) {
            $frame->returnVar->array(VmEnv::getAllEnvironmentTable());

            return;
        }
        // Z_PARAM_STR_OR_NULL: coerce int/float/bool when caller is not strict_types (#4177).
        // Always-typed rejection (#17765) only applies under caller strict_types (php-src-strict).
        $name = VmString::typedNullableStringBuiltinArgForFrame($frame, 0, 'getenv', 0, 'name');
        if (null === $name) {
            $frame->returnVar->array(VmEnv::getAllEnvironmentTable());

            return;
        }
        $result = VmEnv::getenv($name, $localOnly);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError (AOT/JIT) — #28690.
        if (!$this->requireAtMostJitArgCount($context, $args, 'getenv', 2)) {
            return $context->getTypeFromString('__value__*')->constNull();
        }
        $argc = \count($args);
        // Densify pads omitted name with null constant before spread (#24855 / #9525).
        if (
            0 === $argc
            || JITVariable::TYPE_NULL === $args[0]->type
            || ($args[0]->isNullConstant ?? false)
        ) {
            return JitEnv::getenvAll($context);
        }
        $i8 = $context->getTypeFromString('int8');
        $localOnlyI8 = $i8->constInt(0, false);
        if ($argc >= 2 && isset($args[1]) && !(($args[1]->isOptionalOmittedNamedArg ?? false))) {
            $localOnlyI8 = $context->builder->zExt(
                JitBoolArg::lower($context, $args[1], 'getenv() local_only'),
                $i8
            );
        }

        return JitEnv::getenv(
            $context,
            JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $args[0],
                'getenv',
                0,
                'name',
                '?string'
            ),
            $localOnlyI8
        );
    }
}
