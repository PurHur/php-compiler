<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for phpc_strtok via StrtokJitHelper PHP (#9812, #25171).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer StringGetenv #20644).
 * Manual bridges: null __string__* → null flags; string|false → null __string__* for false.
 * php-src: ext/standard/string.c — PHP_FUNCTION(strtok)
 */
final class StringStrtok
{
    private const HELPER_PATH = '/ext/standard/StrtokJitHelper.php';

    private const RESET_HELPER = 'PHPCompiler\\ext\\standard\\StrtokJitHelper::reset';

    private const INIT_HELPER = 'PHPCompiler\\ext\\standard\\StrtokJitHelper::init';

    private const TOKENIZE_HELPER = 'PHPCompiler\\ext\\standard\\StrtokJitHelper::tokenize';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::RESET_HELPER,
        self::INIT_HELPER,
        self::TOKENIZE_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        'phpc_strtok',
        '__phpc_strtok_reset',
        '__phpc_strtok_init',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('phpc_strtok');
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, 'strtok_bridge_entry')) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#9812'
        );
        self::implementResetBridge($context);
        self::implementInitBridge($context);
        self::implementTokenizeBridge($context);
        self::registerLinkedRuntime($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementResetBridge(Context $context): void
    {
        $abiName = '__phpc_strtok_reset';
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, 'strtok_reset_bridge_entry')) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, 'strtok_reset_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->call(self::helperFunction($context, self::RESET_HELPER));
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementInitBridge(Context $context): void
    {
        $abiName = '__phpc_strtok_init';
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, 'strtok_init_bridge_entry')) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($voidTy, false, $strPtr);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, 'strtok_init_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $helper = self::helperFunction($context, self::INIT_HELPER);
        $strArg = $fn->getParam(0);
        $empty = $context->builder->load($context->constantStringFromString(''));
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $strArg,
            $strPtr->constNull()
        );
        $strForHelper = $context->builder->select($isNull, $empty, $strArg);
        $i64 = $context->getTypeFromString('int64');
        $nullFlag = $context->builder->zext($isNull, $i64);
        // string params are __string__* (QuotPrint peer); int may be boxed — coerce only flag.
        $flagArg = JitNestedHelperCoerce::coerceArgForHelper(
            $context,
            $nullFlag,
            $helper->getParam(1)->typeOf()
        );
        $context->builder->call($helper, $strForHelper, $flagArg);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementTokenizeBridge(Context $context): void
    {
        $abiName = 'phpc_strtok';
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, 'strtok_bridge_entry')) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i8 = $context->getTypeFromString('int8');
        $ft = $context->context->functionType($strPtr, false, $strPtr, $strPtr, $i8);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, 'strtok_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $helper = self::helperFunction($context, self::TOKENIZE_HELPER);
        $strArg = $fn->getParam(0);
        $tokArg = $fn->getParam(1);
        $initArg = $fn->getParam(2);
        $empty = $context->builder->load($context->constantStringFromString(''));
        $strNull = $context->builder->icmp(Builder::INT_EQ, $strArg, $strPtr->constNull());
        $tokNull = $context->builder->icmp(Builder::INT_EQ, $tokArg, $strPtr->constNull());
        $strForHelper = $context->builder->select($strNull, $empty, $strArg);
        $tokForHelper = $context->builder->select($tokNull, $empty, $tokArg);
        $i64 = $context->getTypeFromString('int64');
        $strNullFlag = $context->builder->zext($strNull, $i64);
        $tokNullFlag = $context->builder->zext($tokNull, $i64);
        $initI64 = $context->builder->zext($initArg, $i64);

        $raw = $context->builder->call(
            $helper,
            $strForHelper,
            $tokForHelper,
            JitNestedHelperCoerce::coerceArgForHelper($context, $initI64, $helper->getParam(2)->typeOf()),
            JitNestedHelperCoerce::coerceArgForHelper($context, $strNullFlag, $helper->getParam(3)->typeOf()),
            JitNestedHelperCoerce::coerceArgForHelper($context, $tokNullFlag, $helper->getParam(4)->typeOf())
        );

        $isFalse = JitNestedHelperCoerce::isHelperResultNull($context, $raw);
        $fail = $fn->appendBasicBlock('strtok_bridge_false');
        $ok = $fn->appendBasicBlock('strtok_bridge_ok');
        $context->builder->branchIf($isFalse, $fail, $ok);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($strPtr->constNull());

        $context->builder->positionAtEnd($ok);
        $context->builder->returnValue(
            JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw)
        );
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        return JitVmHelperLink::lookupCompiled($context, $logical, '#9812');
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringStrtok bridge (#9812)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
