<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM __phpc_stream_path for fstat() JIT/AOT lowering (issue #6764).
 *
 * Reads fopen paths from phpc_stream_paths[] in lib/AOT/runtime/phpc_stream.c.
 * Replaces deleted C __phpc_stream_path / __phpc_fstat. php-src: ext/standard/streams.c
 */
final class StreamPathRuntime
{
    private const MAX_HANDLES = 256;

    private const GLOBAL_HANDLES = 'phpc_stream_handles';

    private const GLOBAL_PATHS = 'phpc_stream_paths';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__phpc_stream_path');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureExternGlobals($context);
        self::ensureExternals($context);

        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $pathProbe = $context->module->getNamedFunction('__phpc_stream_path');
        $fnPath = null !== $pathProbe
            ? $pathProbe
            : $context->module->addFunction(
                '__phpc_stream_path',
                $context->context->functionType($strPtr, false, $i64)
            );
        self::implementPathLookup($context, $fnPath);

        self::registerLinkedRuntime($context);
    }

    private static function implementPathLookup(Context $context, Value $fn): void
    {
        $entry = $fn->appendBasicBlock('sp_path_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $nullStr = $strPtr->constNull();
        $max = $i64->constInt(self::MAX_HANDLES, false);
        $zero = $i64->constInt(0, false);
        $zeroIdx = $i64->constInt(0, false);

        $badHandle = $context->builder->or(
            $context->builder->icmp(Builder::INT_SLE, $handle, $zero),
            $context->builder->icmp(Builder::INT_SGE, $handle, $max)
        );
        $failBb = $fn->appendBasicBlock('sp_path_fail');
        $openBb = $fn->appendBasicBlock('sp_path_open');
        $context->builder->branchIf($badHandle, $failBb, $openBb);

        $context->builder->positionAtEnd($openBb);
        $fp = self::loadTableSlot($context, self::GLOBAL_HANDLES, $handle);
        $i8p = $context->getTypeFromString('int8*');
        $notOpen = $context->builder->icmp(Builder::INT_EQ, $fp, $i8p->constNull());
        $pathBb = $fn->appendBasicBlock('sp_path_path');
        $context->builder->branchIf($notOpen, $failBb, $pathBb);

        $context->builder->positionAtEnd($pathBb);
        $pathCstr = self::loadTableSlot($context, self::GLOBAL_PATHS, $handle);
        $noPath = $context->builder->icmp(Builder::INT_EQ, $pathCstr, $i8p->constNull());
        $okBb = $fn->appendBasicBlock('sp_path_ok');
        $context->builder->branchIf($noPath, $failBb, $okBb);

        $context->builder->positionAtEnd($okBb);
        $len = $context->builder->call($context->lookupFunction('strlen'), $pathCstr);
        $result = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->sext($len, $i64),
            $pathCstr
        );
        $context->builder->returnValue($result);
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($nullStr);
        $context->builder->clearInsertionPosition();
    }

    private static function loadTableSlot(Context $context, string $globalName, Value $handle): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $zero = $i64->constInt(0, false);
        $global = $context->module->getNamedGlobal($globalName);
        if (null === $global) {
            throw new \LogicException('StreamPathRuntime: '.$globalName.' missing');
        }
        $slot = $context->builder->gep($global, $zero, $handle);

        return $context->builder->load($context->builder->bitcast($slot, $i8p->pointerType(0)));
    }

    private static function ensureExternGlobals(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $tableTy = $i8p->arrayType(self::MAX_HANDLES);
        foreach ([self::GLOBAL_HANDLES, self::GLOBAL_PATHS] as $name) {
            if (null !== $context->module->getNamedGlobal($name)) {
                continue;
            }
            $context->module->addGlobal($tableTy, $name);
        }
    }

    private static function ensureExternals(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $sizeT = $context->getTypeFromString('size_t');

        foreach (
            [
                ['strlen', $sizeT, false, [$i8p]],
                ['__string__init', $strPtr, false, [$i64, $i8p]],
            ] as [$name, $ret, $vararg, $params]
        ) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, $vararg, ...$params));
        }
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction('__phpc_stream_path');
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException('__phpc_stream_path missing after StreamPathRuntime LLVM implement');
        }
        $context->registerFunction('__phpc_stream_path', $fn);
    }
}
