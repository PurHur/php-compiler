<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * get_loaded_extensions() — registered extension list (ext/standard/info.c parity, #3204).
 *
 * Excess argc → Zend ArgumentCountError (#30537; php-src ext/standard/basic_functions.c).
 */
final class get_loaded_extensions extends Internal
{
    public function __construct()
    {
        parent::__construct('get_loaded_extensions');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: 0..1 — #30537.
        $this->requireArgCountRange($frame, 'get_loaded_extensions', 0, 1);
        $argc = \count($frame->calledArgs);
        if (null === $frame->returnVar) {
            return;
        }
        $zendExtensions = false;
        if (1 === $argc) {
            // Z_PARAM_BOOL — strict_types TypeError on null; else null→false + E_DEPRECATED (#30169).
            $zendExtensions = VmMath::parseBoolBuiltinArgForFrame(
                $frame,
                0,
                'get_loaded_extensions',
                1,
                'zend_extensions'
            );
        }
        $frame->returnVar->array(VmInfo::get_loaded_extensions($zendExtensions));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30537 / peer #30536).
        if (!$this->requireArgCountRangeJit($context, $args, 'get_loaded_extensions', 0, 1)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        $zendExtensions = $context->constantFromBool(false);
        if (isset($args[0])) {
            // Compile-time null under strict: catchable TypeError then stop IR (#30169 / peer #29866).
            if ($context->callerStrictTypes && (
                JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false)
            )) {
                JitNativeString::ensureInsertBlock($context);
                ExceptionBridge::emitTypeErrorAndAbort(
                    $context,
                    'get_loaded_extensions(): Argument #1 ($zend_extensions) must be of type bool, null given'
                );
                // Dead resume after catchable throw — empty value box (peer in_array #29866).
                JitNativeString::ensureInsertBlock($context);
                $slot = JitValueBox::alloc($context);

                return JitValueBox::pointer($context, $slot);
            }
            $zendExtensions = JitBoolArg::lowerCoerceZParamBool(
                $context,
                $args[0],
                'get_loaded_extensions',
                'zend_extensions',
                1
            );
        }

        return JitInfo::get_loaded_extensions($context, $zendExtensions);
    }
}
