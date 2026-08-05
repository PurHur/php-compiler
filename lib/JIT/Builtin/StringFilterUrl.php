<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;

/**
 * JIT/AOT link for __compiler_filter_validate_url via FilterUrlValidate PHP (#11274, #26766, #27206).
 *
 * Const string `filter_var(..., FILTER_VALIDATE_URL)` may fold in {@see \PHPCompiler\ext\filter\JitFilter}
 * via VmFilter SSOT. Dynamic strings NestedJIT {@see FilterUrlValidate::isValidInt} (int 0/1) and
 * return the ABI input `__string__*` on success — NestedJIT `?string` / VmFilter / preg paths are
 * corrupt under thin AOT (#26853 / #27068 / #27078 / #26888).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer StringFilterEmail #27068).
 * php-src: ext/filter/logical_filters.c — php_filter_validate_url
 */
final class StringFilterUrl
{
    private const VALIDATE_PATH = '/ext/filter/FilterUrlValidate.php';

    private const VALIDATE_IS_VALID_INT = 'PHPCompiler\\ext\\filter\\FilterUrlValidate::isValidInt';

    /** @var list<string> */
    private const COMPILED_VALIDATE = [
        self::VALIDATE_IS_VALID_INT,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_filter_validate_url',
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
        $probe = $context->module->getNamedFunction('__compiler_filter_validate_url');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        // Restore caller insert block after bridge emit (#20988 / peer StringFilterEmail #27068) —
        // clearInsertionPosition left the user-script builder detached
        // ("Current basic block has no parent function").
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
        $abiName = '__compiler_filter_validate_url';
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

        $entry = $fn->appendBasicBlock('filter_url_bridge_entry');
        $okBlock = $fn->appendBasicBlock('filter_url_bridge_ok');
        $failBlock = $fn->appendBasicBlock('filter_url_bridge_fail');
        $context->builder->positionAtEnd($entry);

        $isValidInt = JitVmHelperLink::lookupCompiled($context, self::VALIDATE_IS_VALID_INT, '#27206');
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
        JitVmHelperLink::ensureCompiled($context, self::VALIDATE_PATH, self::COMPILED_VALIDATE, '#27206');
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringFilterUrl bridge (#11274)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
