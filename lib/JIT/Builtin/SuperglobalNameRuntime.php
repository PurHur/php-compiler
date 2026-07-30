<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_is_superglobal_name via SuperglobalNameJitHelper PHP (#9271, #25091).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer TimezoneOffset #25042).
 * Replaces memcmp LLVM loop; SSOT {@see \PHPCompiler\ext\standard\SuperglobalNames}.
 * php-src: Zend/zend_compile.c — zend_is_auto_global_str
 */
final class SuperglobalNameRuntime
{
    private const HELPER_PATH = '/ext/standard/SuperglobalNameJitHelper.php';

    private const IS_SUPERGLOBAL_HELPER = 'PHPCompiler\\ext\\standard\\SuperglobalNameJitHelper::isSuperglobalName';

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
        $probe = $context->module->getNamedFunction('__compiler_is_superglobal_name');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementBridge($context, $probe);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementBridge(Context $context, ?LlvmFunction $probe): void
    {
        $abiName = '__compiler_is_superglobal_name';
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i64, false, $strPtr)
            );

        $entry = $fn->appendBasicBlock('sg_name_bridge_entry');
        $nullBb = $fn->appendBasicBlock('sg_name_bridge_null');
        $workBb = $fn->appendBasicBlock('sg_name_bridge_work');
        $context->builder->positionAtEnd($entry);

        $name = $fn->getParam(0);
        $zeroI64 = $i64->constInt(0, false);
        $nullName = $context->builder->icmp(Builder::INT_EQ, $name, $strPtr->constNull());
        $context->builder->branchIf($nullName, $nullBb, $workBb);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->returnValue($zeroI64);

        $context->builder->positionAtEnd($workBb);
        $result = $context->builder->call(
            self::helperFunction($context, self::IS_SUPERGLOBAL_HELPER),
            $name
        );
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#25091');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#25091'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction('__compiler_is_superglobal_name');
        if (null === $fn) {
            throw new \LogicException('__compiler_is_superglobal_name missing after SuperglobalNameRuntime bridge (#9271)');
        }
        $context->registerFunction('__compiler_is_superglobal_name', $fn);
    }
}
