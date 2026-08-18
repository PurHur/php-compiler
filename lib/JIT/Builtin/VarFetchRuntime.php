<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\VM\VmVarFetchJitHelper;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for $$name superglobal guard via VmVarFetchJitHelper PHP (#10289, #8708, #25328).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer LastError #25318).
 * php-src: Zend/zend_execute.c — ZEND_FETCH_R/W superglobal branch
 * SSOT: {@see \PHPCompiler\VM\VmVarFetch}
 */
final class VarFetchRuntime
{
    private const HELPER_PATH = '/lib/VM/VmVarFetchJitHelper.php';

    private const IS_SUPERGLOBAL_HELPER = 'PHPCompiler\\VM\\VmVarFetchJitHelper::isSuperglobalName';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::IS_SUPERGLOBAL_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__var_fetch__isSuperglobalName');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        // Mid-block link from get_defined_vars() must restore the caller's insert BB (#30779).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureJitHelperCompiled($context);
        self::implementSuperglobalBridge($context, $probe);
        self::registerLinkedRuntime($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    public static function callIsSuperglobalName(Context $context, Value $namePtr): Value
    {
        self::ensureLinked($context);
        $fn = $context->lookupFunction('__var_fetch__isSuperglobalName');

        return $context->builder->call($fn, $namePtr);
    }

    public static function isSuperglobalNameAtCompileTime(string $name): bool
    {
        return VmVarFetchJitHelper::isSuperglobalName($name);
    }

    private static function implementSuperglobalBridge(Context $context, ?LlvmFunction $probe): void
    {
        $abiName = '__var_fetch__isSuperglobalName';
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        // C-string ABI for cstrToString → NestedJIT helper (not PHP `__string__*`; #30779).
        // `string*` strips to unsupported native type `string` under getTypeFromString.
        $strPtr = $context->getTypeFromString('int8*');
        $i1 = $context->getTypeFromString('int1');
        $ft = $context->context->functionType($i1, false, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('var_fetch_superglobal_bridge_entry');
        $nullBb = $fn->appendBasicBlock('var_fetch_sg_null');
        $workBb = $fn->appendBasicBlock('var_fetch_sg_work');
        $context->builder->positionAtEnd($entry);

        $name = $fn->getParam(0);
        $falseVal = $i1->constInt(0, false);
        $nullName = $context->builder->icmp(Builder::INT_EQ, $name, $name->typeOf()->constNull());
        $context->builder->branchIf($nullName, $nullBb, $workBb);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->returnValue($falseVal);

        $context->builder->positionAtEnd($workBb);
        $phpStr = self::cstrToString($context, $name);
        $resultRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::IS_SUPERGLOBAL_HELPER),
            [$phpStr]
        );
        $result = JitNestedHelperCoerce::coerceBridgeResult($context, $resultRaw, $i1);
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function cstrToString(Context $context, Value $cstr): Value
    {
        self::ensureCstrExternals($context);
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');
        // strlen(3) via LibcExtern::ensureStrlenDecl after always-on drop (#32068).
        LibcExtern::ensureStrlenDecl($context);
        $len = $context->builder->call($context->lookupFunction('strlen'), $cstr);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($len, $i64),
            $context->builder->pointerCast($cstr, $charPtr)
        );
    }

    private static function ensureCstrExternals(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');

        // strlen(3) via LibcExtern::ensureStrlenDecl after always-on drop (#32068).
        LibcExtern::ensureStrlenDecl($context);
        try {
            $context->lookupFunction('__string__init');
        } catch (\Throwable) {
            $fn = $context->module->addFunction(
                '__string__init',
                $context->context->functionType($strPtr, false, $i64, $charPtr)
            );
            $context->registerFunction('__string__init', $fn);
        }
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#25328');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#25328'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction('__var_fetch__isSuperglobalName');
        if (null === $fn) {
            throw new \LogicException('__var_fetch__isSuperglobalName missing after VarFetchRuntime bridge (#10289)');
        }
        $context->registerFunction('__var_fetch__isSuperglobalName', $fn);
    }
}
