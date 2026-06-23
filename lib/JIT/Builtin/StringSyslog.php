<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_syslog_* via SyslogJitHelper PHP (#9254).
 *
 * Replaces libc openlog/syslog/closelog LLVM; SSOT {@see \PHPCompiler\ext\standard\VmSyslog}.
 * php-src: ext/standard/syslog.c
 */
final class StringSyslog
{
    private const HELPER_PATH = '/ext/standard/SyslogJitHelper.php';

    private const OPENLOG_HELPER = 'PHPCompiler\\ext\\standard\\SyslogJitHelper::openlog';

    private const WRITE_HELPER = 'PHPCompiler\\ext\\standard\\SyslogJitHelper::write';

    private const CLOSELOG_HELPER = 'PHPCompiler\\ext\\standard\\SyslogJitHelper::closelog';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::OPENLOG_HELPER,
        self::WRITE_HELPER,
        self::CLOSELOG_HELPER,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_syslog_openlog',
        '__compiler_syslog_write',
        '__compiler_syslog_closelog',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_syslog_write');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementOpenlogBridge($context);
        self::implementWriteBridge($context);
        self::implementCloselogBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementOpenlogBridge(Context $context): void
    {
        $abiName = '__compiler_syslog_openlog';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $voidTy = $context->getTypeFromString('void');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($voidTy, false, $strPtr, $i32, $i32)
            );

        $entry = $fn->appendBasicBlock('sl_open_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $i64 = $context->getTypeFromString('int64');
        $context->builder->call(
            self::helperFunction($context, self::OPENLOG_HELPER),
            $fn->getParam(0),
            $context->builder->sext($fn->getParam(1), $i64),
            $context->builder->sext($fn->getParam(2), $i64)
        );
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementWriteBridge(Context $context): void
    {
        $abiName = '__compiler_syslog_write';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $voidTy = $context->getTypeFromString('void');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($voidTy, false, $i32, $strPtr)
            );

        $entry = $fn->appendBasicBlock('sl_write_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $i64 = $context->getTypeFromString('int64');
        $context->builder->call(
            self::helperFunction($context, self::WRITE_HELPER),
            $context->builder->sext($fn->getParam(0), $i64),
            $fn->getParam(1)
        );
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementCloselogBridge(Context $context): void
    {
        $abiName = '__compiler_syslog_closelog';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($voidTy, false)
            );

        $entry = $fn->appendBasicBlock('sl_close_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->call(self::helperFunction($context, self::CLOSELOG_HELPER));
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after SyslogJitHelper compile (#9254)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $missing = false;
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'SyslogJitHelper.php');
            if (null === $block) {
                throw new \LogicException('SyslogJitHelper.php parseAndCompile failed (#9254)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9254)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringSyslog PHP bridge (#9254)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
