<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;

/**
 * Standalone AOT process locale startup — php-src {@see zend_reset_lc_ctype_locale} (#30789).
 *
 * php-src Zend/zend_operators.c:
 *   if (!setlocale(LC_CTYPE, "C.UTF-8")) {
 *       setlocale(LC_CTYPE, "C");
 *   }
 * Called from php_module_startup so idle {@see nl_langinfo}(CODESET) returns UTF-8
 * (not ANSI_X3.4-1968 from classic C). Thin libc trampoline only — no NestedJIT.
 *
 * Emits a separate void function then a single call from {@code standalone_main}
 * (Context::emitInStandaloneMain always repositions to that block).
 */
final class LocaleStartupRuntime
{
    private const ABI = '__phpc_zend_reset_lc_ctype';

    public static function emitResetLcCtypeForStandaloneMain(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE !== $context->loadType) {
            return;
        }

        self::ensureResetFunction($context);
        $context->builder->call($context->lookupFunction(self::ABI));
    }

    private static function ensureResetFunction(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        self::ensureSetlocaleDecl($context);

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $void = $context->getTypeFromString('void');
        $fn = $probe ?? $context->module->addFunction(
            self::ABI,
            $context->context->functionType($void, false)
        );
        $context->registerFunction(self::ABI, $fn);

        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $lcCtype = $i32->constInt(self::lcCtypeCategory(), true);
        $setlocale = $context->lookupFunction('setlocale');
        $cUtf8 = $context->builder->pointerCast(
            $context->constantFromString('C.UTF-8'),
            $i8p
        );
        $got = $context->builder->call($setlocale, $lcCtype, $cUtf8);
        $failed = $context->builder->icmp(Builder::INT_EQ, $got, $i8p->constNull());
        $fallbackBb = $fn->appendBasicBlock('fallback_c');
        $doneBb = $fn->appendBasicBlock('done');
        $context->builder->branchIf($failed, $fallbackBb, $doneBb);

        $context->builder->positionAtEnd($fallbackBb);
        $cLocale = $context->builder->pointerCast(
            $context->constantFromString('C'),
            $i8p
        );
        $context->builder->call($setlocale, $lcCtype, $cLocale);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();

        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
    }

    private static function lcCtypeCategory(): int
    {
        return \defined('LC_CTYPE') ? (int) \constant('LC_CTYPE') : 0;
    }

    private static function ensureSetlocaleDecl(Context $context): void
    {
        try {
            $context->lookupFunction('setlocale');
        } catch (\Throwable) {
            $i8p = $context->getTypeFromString('int8*');
            $i32 = $context->getTypeFromString('int32');
            $fn = $context->module->addFunction(
                'setlocale',
                $context->context->functionType($i8p, false, $i32, $i8p)
            );
            $context->registerFunction('setlocale', $fn);
        }
    }
}
