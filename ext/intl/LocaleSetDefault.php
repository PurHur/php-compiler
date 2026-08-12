<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/** Locale::setDefault() — OOP wrapper for {@see VmLocale::setDefault()} (#9576). */
final class LocaleSetDefault extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setDefault');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'Locale::setDefault() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        $locale = VmLocale::coerceLocaleArg(
            $frame->calledArgs[0],
            'Locale::setDefault',
            0
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmLocale::setDefault($locale));
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) >= 1) {
            $locale = $args[0];
            $nullConst = JITVariable::TYPE_NULL === $locale->type
                || ($locale->isNullConstant ?? false);
            if ($nullConst) {
                JitNativeString::ensureInsertBlock($context);
                ExceptionBridge::emitTypeErrorAndAbort(
                    $context,
                    'Locale::setDefault(): Argument #1 ($locale) must be of type string, null given'
                );
                $slot = JitValueBox::alloc($context);
                $ptr = JitValueBox::pointer($context, $slot);
                $context->builder->call(
                    $context->lookupFunction('__value__writeNull'),
                    $ptr
                );

                return JitValueBox::normalizeValuePtr($context, $ptr);
            }
        }

        throw new \LogicException(
            'Locale::setDefault() JIT runtime lowering is deferred; use VM (#9576)'
        );
    }
}
