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

/**
 * get_defined_functions() — internal and user function name lists (issue #3128).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(get_defined_functions)
 */
final class get_defined_functions_ extends Internal
{
    public function __construct()
    {
        parent::__construct('get_defined_functions');
    }

    public function execute(Frame $frame): void
    {
        $excludeDisabled = VmReflection::parseExcludeDisabledArg($frame, 'get_defined_functions');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(
            VmReflection::definedFunctionsTable(
                VmReflection::requireContext($frame),
                $excludeDisabled
            )
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $literal = GetDefinedExcludeDisabledJit::parseLiteral($context, $args, 'get_defined_functions');
        if (isset($args[0])) {
            // Z_PARAM_BOOL — strict null TypeError / soft DEP+false (#30169).
            if ($context->callerStrictTypes && (
                JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false)
            )) {
                JitNativeString::ensureInsertBlock($context);
                ExceptionBridge::emitTypeErrorAndAbort(
                    $context,
                    'get_defined_functions(): Argument #1 ($exclude_disabled) must be of type bool, null given'
                );
                // Catchable throw closed the block — resume with a dummy return value.
                JitNativeString::ensureInsertBlock($context);

                return JitGetDefinedFunctions::invoke($context, false);
            }
            JitBoolArg::lowerCoerceZParamBool(
                $context,
                $args[0],
                'get_defined_functions',
                'exclude_disabled',
                1
            );
            if (null === $literal
                && (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false))
            ) {
                $literal = false;
            }
        }

        return JitGetDefinedFunctions::invoke($context, $literal ?? false);
    }
}
