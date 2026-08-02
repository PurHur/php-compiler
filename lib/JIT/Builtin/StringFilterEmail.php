<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;

/**
 * JIT/AOT link for __compiler_filter_validate_email via FilterEmailValidate PHP (#9860).
 *
 * Const string `filter_var(..., FILTER_VALIDATE_EMAIL)` folds in {@see \PHPCompiler\ext\filter\JitFilter}
 * via FilterEmailValidate SSOT (peer FILTER_VALIDATE_BOOL / #26853) — NestedJIT string
 * indexing and `?string` returns are corrupt under thin AOT (#27068).
 *
 * Dynamic strings still NestedJIT {@see FilterEmailValidate::isValidInt} (int 0/1) and
 * return the ABI input `__string__*` on success. FilterEmailValidate stays free of
 * `\preg_match` so NestedJIT does not emit `__compiler_preg_match` (#27068).
 *
 * php-src: ext/filter/logical_filters.c — php_filter_validate_email
 */
final class StringFilterEmail
{
    private const VALIDATE_PATH = '/ext/filter/FilterEmailValidate.php';

    private const VALIDATE_IS_VALID_INT = 'PHPCompiler\\ext\\filter\\FilterEmailValidate::isValidInt';

    /** @var list<string> */
    private const COMPILED_VALIDATE = [
        self::VALIDATE_IS_VALID_INT,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_filter_validate_email',
    ];

    public static function ensureLinked(Context $context): void
    {
        $restore = BasicBlockHelper::tryGetInsertBlock($context);
        self::implement($context);
        if (null !== $restore) {
            BasicBlockHelper::restoreInsertBlock($context, $restore);
        }
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_filter_validate_email');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureJitHelperCompiled($context);
        self::implementValidateBridge($context);
        self::registerLinkedRuntime($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementValidateBridge(Context $context): void
    {
        $abiName = '__compiler_filter_validate_email';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($strPtr, false, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('filter_email_bridge_entry');
        $okBlock = $fn->appendBasicBlock('filter_email_bridge_ok');
        $failBlock = $fn->appendBasicBlock('filter_email_bridge_fail');
        $context->builder->positionAtEnd($entry);

        $isValidInt = JitVmHelperLink::lookupCompiled($context, self::VALIDATE_IS_VALID_INT, '#22826');
        $flagsZero = $i64->constInt(0, false);
        $rawOk = JitNestedHelperCoerce::callHelper(
            $context,
            $isValidInt,
            [$fn->getParam(0), $flagsZero]
        );
        $okI64 = JitNestedHelperCoerce::coerceBridgeResult($context, $rawOk, $i64);
        $ok = $context->builder->icmp(
            Builder::INT_NE,
            $okI64,
            $i64->constInt(0, false)
        );
        $context->builder->branchIf($ok, $okBlock, $failBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->returnValue($fn->getParam(0));

        $context->builder->positionAtEnd($failBlock);
        $context->builder->returnValue($strPtr->constNull());

        $context->registerFunction($abiName, $fn);
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled($context, self::VALIDATE_PATH, self::COMPILED_VALIDATE, '#22826');
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringFilterEmail bridge (#9860)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
