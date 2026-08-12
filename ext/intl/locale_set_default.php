<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** locale_set_default() — set default BCP-47 locale id (php-src ext/intl/php_intl.c; #9576). */
final class locale_set_default extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'locale_set_default() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        $locale = VmLocale::coerceLocaleArg(
            $frame->calledArgs[0],
            'locale_set_default',
            0
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmLocale::setDefault($locale));
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'locale_set_default() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        // Compile-time / typed null — Z_PARAM_STR (#29932, locale.stub.php).
        $nullConst = JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false);
        if ($nullConst) {
            JitNativeString::ensureInsertBlock($context);
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                'locale_set_default(): Argument #1 ($locale) must be of type string, null given'
            );

            return self::boxNullResult($context);
        }

        throw new \LogicException(
            'locale_set_default() JIT runtime lowering is deferred; use VM (#9576)'
        );
    }

    private static function boxNullResult(Context $context): Value
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
