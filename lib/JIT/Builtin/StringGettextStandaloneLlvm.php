<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Standalone AOT LLVM for gettext ABI when nested GettextJitHelper cannot compile (#9859).
 *
 * Passthrough msgid + in-process domain state (no libc gettext globals).
 */
final class StringGettextStandaloneLlvm
{
    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_gettext');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::implementStringFn($context, '__compiler_gettext', self::emitPassthroughString(...));
        self::implementStringFn($context, '__compiler_dgettext', self::emitPassthroughSecond(...));
        self::implementStringFn($context, '__compiler_dcgettext', self::emitPassthroughSecond(...));
        self::implementStringFn($context, '__compiler_dngettext', self::emitPluralPassthrough(...));
        self::implementStringFn($context, '__compiler_dcngettext', self::emitPluralPassthrough(...));
        self::implementBindtextdomainStandalone($context);
        self::implementTextdomainStandalone($context);
        self::implementCodesetStandalone($context);
        self::registerLinkedRuntime($context);
    }

    /**
     * @param callable(Context, LlvmFunction): void $emit
     */
    private static function implementStringFn(
        Context $context,
        string $name,
        callable $emit
    ): void {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $params = match ($name) {
            '__compiler_gettext' => [$strPtr],
            '__compiler_dgettext' => [$strPtr, $strPtr],
            '__compiler_dcgettext' => [$strPtr, $strPtr, $i64],
            '__compiler_dngettext' => [$strPtr, $strPtr, $strPtr, $i64],
            '__compiler_dcngettext' => [$strPtr, $strPtr, $strPtr, $i64, $i64],
            default => throw new \LogicException('unexpected gettext standalone fn: '.$name),
        };
        $fn = $context->module->addFunction(
            $name,
            $context->context->functionType($strPtr, false, ...$params)
        );
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function emitPassthroughString(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue(self::copyString($context, $fn->getParam(0)));
    }

    private static function emitPassthroughSecond(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue(self::copyString($context, $fn->getParam(1)));
    }

    private static function emitPluralPassthrough(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $oneBb = $fn->appendBasicBlock('one');
        $manyBb = $fn->appendBasicBlock('many');
        $doneBb = $fn->appendBasicBlock('done');
        $context->builder->positionAtEnd($entry);

        $i64 = $context->getTypeFromString('int64');
        $count = $fn->getParam(3);
        $isOne = $context->builder->icmp(Builder::INT_EQ, $count, $i64->constInt(1, false));
        $context->builder->branchIf($isOne, $oneBb, $manyBb);

        $context->builder->positionAtEnd($oneBb);
        $first = self::copyString($context, $fn->getParam(1));
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($manyBb);
        $second = self::copyString($context, $fn->getParam(2));
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $strPtr = $context->getTypeFromString('__string__*');
        $phi = $context->builder->phi($strPtr);
        $phi->addIncoming($first, $oneBb);
        $phi->addIncoming($second, $manyBb);
        $context->builder->returnValue($phi);
    }

    private static function implementBindtextdomainStandalone(Context $context): void
    {
        self::implementOptionalStringOutStandalone($context, '__compiler_bindtextdomain');
    }

    private static function implementTextdomainStandalone(Context $context): void
    {
        $abiName = '__compiler_textdomain';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $strPtr = $context->getTypeFromString('__string__*');
        $valPtr = $context->getTypeFromString('__value__*');
        $fn = $context->module->addFunction(
            $abiName,
            $context->context->functionType($voidTy, false, $strPtr, $valPtr)
        );

        $entry = $fn->appendBasicBlock('entry');
        $falseBb = $fn->appendBasicBlock('false');
        $okBb = $fn->appendBasicBlock('ok');
        $context->builder->positionAtEnd($entry);

        $domain = $fn->getParam(0);
        $out = $fn->getParam(1);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $domain, $strPtr->constNull());
        $context->builder->branchIf($isNull, $falseBb, $okBb);

        $context->builder->positionAtEnd($falseBb);
        self::writeBoolFalse($context, $out);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($okBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $out,
            self::copyString($context, $domain)
        );
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementCodesetStandalone(Context $context): void
    {
        self::implementOptionalStringOutStandalone($context, '__compiler_bind_textdomain_codeset');
    }

    private static function implementOptionalStringOutStandalone(Context $context, string $abiName): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $strPtr = $context->getTypeFromString('__string__*');
        $valPtr = $context->getTypeFromString('__value__*');
        $fn = $context->module->addFunction(
            $abiName,
            $context->context->functionType($voidTy, false, $strPtr, $strPtr, $valPtr)
        );

        $entry = $fn->appendBasicBlock('entry');
        $falseBb = $fn->appendBasicBlock('false');
        $okBb = $fn->appendBasicBlock('ok');
        $context->builder->positionAtEnd($entry);

        $optional = $fn->getParam(1);
        $out = $fn->getParam(2);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $optional, $strPtr->constNull());
        $context->builder->branchIf($isNull, $falseBb, $okBb);

        $context->builder->positionAtEnd($falseBb);
        self::writeBoolFalse($context, $out);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($okBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $out,
            self::copyString($context, $optional)
        );
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function writeBoolFalse(Context $context, Value $out): void
    {
        $i32 = $context->getTypeFromString('int32');
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $out,
            $i32->constInt(0, false)
        );
    }

    private static function copyString(Context $context, Value $subject): Value
    {
        $map = $context->structFieldMap['__string__'];
        $slen = $context->builder->load($context->builder->structGep($subject, $map['length']));
        $sdata = $context->builder->structGep($subject, $map['value']);

        return $context->builder->call($context->lookupFunction('__string__init'), $slen, $sdata);
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach ([
            '__compiler_gettext',
            '__compiler_dgettext',
            '__compiler_dcgettext',
            '__compiler_dngettext',
            '__compiler_dcngettext',
            '__compiler_bindtextdomain',
            '__compiler_textdomain',
            '__compiler_bind_textdomain_codeset',
        ] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after StringGettextStandaloneLlvm (#9859)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
