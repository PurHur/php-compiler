<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for checkdnsrr() via CheckdnsrrJitHelper PHP (#9379, #22355).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer PasswordRandomBytesRuntime #22313).
 * Replaces hand-written libc DNS resolver LLVM. SSOT: {@see \PHPCompiler\ext\standard\VmDns}.
 * php-src: ext/standard/dns.c — PHP_FUNCTION(checkdnsrr)
 */
final class CheckdnsrrRuntime
{
    private const HELPER_PATH = '/ext/standard/CheckdnsrrJitHelper.php';

    private const CHECK_HELPER = 'PHPCompiler\\ext\\standard\\CheckdnsrrJitHelper::check';

    private const ABI_NAME = '__compiler_checkdnsrr';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::CHECK_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        // Preserve caller insert block — clearInsertionPosition alone orphans mid-emit (#27406).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureJitHelperCompiled($context);
        self::implementCheckBridge($context);
        self::registerLinkedRuntime($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementCheckBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_NAME, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i32, false, $strPtr, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(self::ABI_NAME, $ft);

        $entry = $fn->appendBasicBlock('cdrr_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $found = $context->builder->call(
            self::helperFunction($context, self::CHECK_HELPER),
            $fn->getParam(0),
            $fn->getParam(1)
        );
        $context->builder->returnValue($context->builder->zext($found, $i32));
        $context->registerFunction(self::ABI_NAME, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#22355');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#22355'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_NAME);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_NAME.' missing after CheckdnsrrRuntime bridge (#9379)');
        }
        $context->registerFunction(self::ABI_NAME, $fn);
    }
}
