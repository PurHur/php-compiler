<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * get_browser() — browscap user-agent lookup (ext/standard/browscap.c, #11172).
 *
 * php-src: ext/standard/browscap.c — php_get_browser
 */
final class get_browser extends Internal
{
    public function __construct()
    {
        parent::__construct('get_browser');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'get_browser() expects at most 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar || null === $frame->vmContext) {
            return;
        }

        $ua = null;
        $returnArray = false;
        if ($argc >= 1) {
            $uaArg = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_NULL !== $uaArg->type) {
                $ua = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'get_browser', 0, 'browser_name');
            }
        }
        if ($argc >= 2) {
            // Z_PARAM_BOOL — strict_types TypeError on null (#31289); soft null → DEP + false.
            $returnArray = VmMath::parseBoolBuiltinArgForFrame(
                $frame,
                1,
                'get_browser',
                2,
                'return_array'
            );
        }

        if (!VmBrowser::browscapConfigured($frame->vmContext)) {
            VmBrowser::triggerBrowscapNotSetWarning(
                $frame->vmContext,
                '' !== $frame->scriptPath ? $frame->scriptPath : null,
                $frame
            );
            BuiltinExecute::writeReturn($frame, static function (Variable $ret): void {
                $ret->bool(false);
            });

            return;
        }

        $result = VmBrowser::lookup($frame->vmContext, $frame, $ua);
        if (false === $result) {
            BuiltinExecute::writeReturn($frame, static function (Variable $ret): void {
                $ret->bool(false);
            });

            return;
        }

        if ($returnArray) {
            BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
                $ret->copyFrom(VmJson::import($result));
            });
        } else {
            $ctx = $frame->vmContext;
            BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result, $ctx): void {
                $ret->copyFrom(VmJson::importDecoded((object) $result, false, $ctx));
            });
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 2) {
            throw new \LogicException('get_browser() expects at most 2 arguments in this compiler build');
        }
        if ($argc >= 1 && JITVariable::TYPE_NULL !== $args[0]->type) {
            JitStringBuiltinArg::lower($context, $args[0], 'get_browser', 0, 'browser_name');
        }
        if ($argc >= 2) {
            // Compile-time null under strict: catchable TypeError then stop IR (#31289 / peer hash #31288).
            if ($context->callerStrictTypes && (
                JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false)
            )) {
                JitNativeString::ensureInsertBlock($context);
                ExceptionBridge::emitTypeErrorAndAbort(
                    $context,
                    'get_browser(): Argument #2 ($return_array) must be of type bool, null given'
                );
                JitNativeString::ensureInsertBlock($context);
                $slot = JitValueBox::alloc($context);

                return JitValueBox::pointer($context, $slot);
            }
            // Z_PARAM_BOOL — soft null → false + E_DEPRECATED (#31289).
            JitBoolArg::lowerCoerceZParamBool(
                $context,
                $args[1],
                'get_browser',
                'return_array',
                2
            );
        }

        return JitGetBrowser::invoke($context);
    }
}
