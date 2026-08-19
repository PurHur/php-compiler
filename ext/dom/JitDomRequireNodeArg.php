<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_;

/**
 * Compile-time null DOMNode args → Zend TypeError (#30410 / #32558).
 *
 * php-src: ext/dom/node.c Z_PARAM_OBJ_OF_CLASS — null must not reach readObject.
 */
final class JitDomRequireNodeArg
{
    public static function isNullConstant(JITVariable $arg): bool
    {
        return JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false);
    }

    public static function typeErrorMessage(
        string $function,
        int $userArgIndex,
        string $paramName
    ): string {
        return \sprintf(
            '%s(): Argument #%d ($%s) must be of type DOMNode, null given',
            $function,
            $userArgIndex,
            $paramName
        );
    }

    /**
     * Emit TypeError and return a null __value__* for unreachable continuation blocks.
     */
    public static function emitTypeErrorAndReturnNull(
        Context $context,
        string $function,
        int $userArgIndex,
        string $paramName
    ): Value {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_node_arg_null_te');
        JitNativeString::ensureInsertBlock($context);
        $message = self::typeErrorMessage($function, $userArgIndex, $paramName);

        if (null !== TryCatchHelper::resolveThrowHandler($context)) {
            ExceptionBridge::emitTypeErrorAndAbort($context, $message);

            return self::boxNullResult($context);
        }

        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            TypeErrorRaise::registerDeclarations($context);
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::ensureStandaloneBodies($context);
            TryCatchHelper::emitPendTypeErrorForCaller($context, $message);
            TypeErrorRaise::emitRaise($context, $message);
            $fn = $context->builder->getInsertBlock()?->getParent();
            if ($fn instanceof Function_) {
                TryCatchHelper::emitPropagateReturnAfterPendingThrow($context, $fn);
            }

            return self::boxNullResult($context);
        }

        ExceptionBridge::emitTypeErrorAndAbort($context, $message);

        return self::boxNullResult($context);
    }

    public static function boxNullResult(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $ptr
        );

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }
}
