<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT bridges for getprotobyname()/getservbyname() via NetworkServicesNameLookupJitHelper PHP (#13441).
 */
final class StringNetworkServicesNameLookup
{
    private const HELPER_PATH = '/ext/standard/NetworkServicesNameLookupJitHelper.php';

    private const VM_NETWORK_PATH = '/ext/standard/VmNetworkServices.php';

    private const GETPROTOBYNAME_HELPER = 'PHPCompiler\\ext\\standard\\NetworkServicesNameLookupJitHelper::getprotobynameLookup';

    private const GETSERVBYNAME_HELPER = 'PHPCompiler\\ext\\standard\\NetworkServicesNameLookupJitHelper::getservbynameLookup';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::GETPROTOBYNAME_HELPER,
        self::GETSERVBYNAME_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__phpc_getprotobyname',
        '__phpc_getservbyname',
    ];

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__phpc_getprotobyname');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureRuntimeHelpers($context);
        self::ensureJitHelperCompiled($context);
        self::implementGetprotobynameBridge($context);
        self::implementGetservbynameBridge($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementGetprotobynameBridge(Context $context): void
    {
        $abiName = '__phpc_getprotobyname';
        $voidTy = $context->getTypeFromString('void');
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($voidTy, false, $strPtr, $valuePtr);
        $fn = $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('getprotobyname_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $nameSep = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $fn->getParam(0)
        );
        $result = $context->builder->call(
            self::helperFunction($context, self::GETPROTOBYNAME_HELPER),
            $nameSep
        );
        $sentinel = $i64->constInt(-1, true);
        $isFail = $context->builder->icmp(Builder::INT_EQ, $result, $sentinel);
        $failBb = $fn->appendBasicBlock('getprotobyname_bridge_fail');
        $okBb = $fn->appendBasicBlock('getprotobyname_bridge_ok');
        $context->builder->branchIf($isFail, $failBb, $okBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $fn->getParam(1),
            $context->getTypeFromString('int32')->constInt(0, false)
        );
        $retBb = $fn->appendBasicBlock('getprotobyname_bridge_ret');
        $context->builder->branch($retBb);

        $context->builder->positionAtEnd($okBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $fn->getParam(1),
            $result
        );
        $context->builder->branch($retBb);

        $context->builder->positionAtEnd($retBb);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementGetservbynameBridge(Context $context): void
    {
        $abiName = '__phpc_getservbyname';
        $voidTy = $context->getTypeFromString('void');
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($voidTy, false, $strPtr, $strPtr, $valuePtr);
        $fn = $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('getservbyname_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $serviceSep = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $fn->getParam(0)
        );
        $protoSep = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $fn->getParam(1)
        );
        $result = $context->builder->call(
            self::helperFunction($context, self::GETSERVBYNAME_HELPER),
            $serviceSep,
            $protoSep
        );
        $sentinel = $i64->constInt(-1, true);
        $isFail = $context->builder->icmp(Builder::INT_EQ, $result, $sentinel);
        $failBb = $fn->appendBasicBlock('getservbyname_bridge_fail');
        $okBb = $fn->appendBasicBlock('getservbyname_bridge_ok');
        $context->builder->branchIf($isFail, $failBb, $okBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $fn->getParam(2),
            $context->getTypeFromString('int32')->constInt(0, false)
        );
        $retBb = $fn->appendBasicBlock('getservbyname_bridge_ret');
        $context->builder->branch($retBb);

        $context->builder->positionAtEnd($okBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $fn->getParam(2),
            $result
        );
        $context->builder->branch($retBb);

        $context->builder->positionAtEnd($retBb);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after NetworkServicesNameLookupJitHelper compile (#13441)');
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
        $root = \dirname(__DIR__, 3);
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $root): void {
            $jit = new JIT($context);
            foreach ([self::VM_NETWORK_PATH, self::HELPER_PATH] as $relative) {
                $path = $root.$relative;
                $real = \realpath($path) ?: $path;
                if ($context->hasJitIncludedFileCompiled($real)) {
                    continue;
                }
                $block = $runtime->parseAndCompile(
                    (string) \file_get_contents($path),
                    \basename($path)
                );
                if (null === $block) {
                    throw new \LogicException(\basename($path).' parseAndCompile failed (#13441)');
                }
                $jit->compile($block);
                $context->markJitIncludedFileCompiled($real);
            }
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                throw new \LogicException($logical.' was not compiled for JIT (#13441)');
            }
        }
    }

    private static function ensureRuntimeHelpers(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $voidTy = $context->getTypeFromString('void');
        $valuePtr = $context->getTypeFromString('__value__*');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');

        foreach (
            [
                ['__string__separate', $strPtr, [$strPtr]],
                ['__value__writeBool', $voidTy, [$valuePtr, $i32]],
                ['__value__writeLong', $voidTy, [$valuePtr, $i64]],
            ] as [$name, $ret, $params]
        ) {
            try {
                $context->lookupFunction($name);
            } catch (\Throwable) {
                $fn = $context->module->addFunction(
                    $name,
                    $context->context->functionType($ret, false, ...$params)
                );
                $context->registerFunction($name, $fn);
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringNetworkServicesNameLookup bridge (#13441)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
