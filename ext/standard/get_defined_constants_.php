<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** get_defined_constants() — runtime constant introspection (issue #3135). */
final class get_defined_constants_ extends Internal
{
    public function __construct()
    {
        parent::__construct('get_defined_constants');
    }

    public function execute(Frame $frame): void
    {
        $ctx = VmReflection::requireContext($frame);
        $categorize = self::parseArgs($frame);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(VmConstants::getDefinedConstants($ctx, $categorize));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 1) {
            throw new \ArgumentCountError(
                \sprintf('get_defined_constants() expects at most 1 argument, %d given', \count($args))
            );
        }
        // Z_PARAM_BOOL — strict null TypeError / soft DEP+false at call boundary (#30169).
        if (isset($args[0]) && $context->callerStrictTypes && (
            JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false)
        )) {
            JitNativeString::ensureInsertBlock($context);
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                'get_defined_constants(): Argument #1 ($categorize) must be of type bool, null given'
            );
            JitNativeString::ensureInsertBlock($context);

            return JitGetDefinedConstants::invoke($context, null);
        }
        if (isset($args[0]) && (
            JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false)
        )) {
            JitBoolArg::lowerCoerceZParamBool(
                $context,
                $args[0],
                'get_defined_constants',
                'categorize',
                1
            );

            return JitGetDefinedConstants::invoke($context, null);
        }

        return JitGetDefinedConstants::invoke($context, $args[0] ?? null);
    }

    private static function parseArgs(Frame $frame): bool
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(
                \sprintf('get_defined_constants() expects at most 1 argument, %d given', $argc)
            );
        }
        if (0 === $argc) {
            return false;
        }

        // Z_PARAM_BOOL — strict_types TypeError on null; else null→false + E_DEPRECATED (#30169).
        return VmMath::parseBoolBuiltinArgForFrame(
            $frame,
            0,
            'get_defined_constants',
            1,
            'categorize'
        );
    }
}
