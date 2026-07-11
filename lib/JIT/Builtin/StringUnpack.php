<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_unpack via UnpackJitHelper PHP (#9543).
 *
 * JIT/normal modules use compiled {@see UnpackJitHelper}; standalone uses the same
 * PHP bridge once UnpackEngine nested JIT is stable (#13063).
 * php-src: ext/standard/pack.c — php_unpack()
 */
final class StringUnpack
{
    private const HELPER_PATH = '/ext/standard/UnpackJitHelper.php';

    private const ENGINE_PATH = '/ext/standard/UnpackEngine.php';

    private const UNPACK_HELPER = 'PHPCompiler\\ext\\standard\\UnpackJitHelper::unpackArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::UNPACK_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_unpack',
    ];

    private const ERR_LEVEL = 2;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_unpack');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureRuntimeHelpers($context);
        self::ensureJitHelperCompiled($context);
        self::implementUnpackBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementUnpackBridge(Context $context): void
    {
        $abiName = '__compiler_unpack';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $voidTy = $context->getTypeFromString('void');
        $valuePtr = $context->getTypeFromString('__value__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $ft = $context->context->functionType($voidTy, false, $strPtr, $strPtr, $i64, $valuePtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('unpack_bridge_entry');
        $fmtNull = $fn->appendBasicBlock('unpack_bridge_fmt_null');
        $dataCheck = $fn->appendBasicBlock('unpack_bridge_data_check');
        $body = $fn->appendBasicBlock('unpack_bridge_body');
        $context->builder->positionAtEnd($entry);

        $fmt = $fn->getParam(0);
        $data = $fn->getParam(1);
        $offset = $fn->getParam(2);
        $out = $fn->getParam(3);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $fmt, $strPtr->constNull()),
            $fmtNull,
            $dataCheck
        );

        $i8p = $context->getTypeFromString('int8*');
        $context->builder->positionAtEnd($fmtNull);
        self::emitWarningAndFalse($context, $fn, $out, 'unpack(): Argument #1 ($format) must be of type string');

        $context->builder->positionAtEnd($dataCheck);
        $dataNull = $fn->appendBasicBlock('unpack_bridge_data_null');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $data, $strPtr->constNull()),
            $dataNull,
            $body
        );

        $context->builder->positionAtEnd($dataNull);
        self::emitWarningAndFalse($context, $fn, $out, 'unpack(): Argument #2 ($data) must be of type string');

        $context->builder->positionAtEnd($body);
        $fmtSep = $context->builder->call($context->lookupFunction('__string__separate'), $fmt);
        $dataSep = $context->builder->call($context->lookupFunction('__string__separate'), $data);
        $ht = $context->builder->call(
            self::helperFunction($context, self::UNPACK_HELPER),
            $fmtSep,
            $dataSep,
            $offset
        );
        $failBb = $fn->appendBasicBlock('unpack_bridge_fail');
        $successBb = $fn->appendBasicBlock('unpack_bridge_success');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $ht, $htPtr->constNull()),
            $failBb,
            $successBb
        );

        $i8 = $context->getTypeFromString('int8');
        $context->builder->positionAtEnd($failBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $out,
            $i8->constInt(0, false)
        );
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($successBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $out,
            $ht
        );
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function emitWarningAndFalse(
        Context $context,
        LlvmFunction $fn,
        Value $out,
        string $message
    ): void {
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $msg = $context->builder->pointerCast(
            $context->constantFromString($message),
            $i8p
        );
        $msgLen = $context->builder->call($context->lookupFunction('strlen'), $msg);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msg,
            $msgLen,
            $i32->constInt(self::ERR_LEVEL, false),
            $context->builder->pointerCast($context->constantFromString(''), $i8p),
            $i32->constInt(0, false)
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $out,
            $i8->constInt(0, false)
        );
        $context->builder->returnVoid();
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after UnpackJitHelper compile (#9543)');
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
            foreach ([self::ENGINE_PATH, self::HELPER_PATH] as $relative) {
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
                    throw new \LogicException(\basename($path).' parseAndCompile failed (#9543)');
                }
                $jit->compile($block);
                $context->markJitIncludedFileCompiled($real);
            }
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                throw new \LogicException($logical.' was not compiled for JIT (#9543)');
            }
        }
    }

    private static function ensureRuntimeHelpers(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $voidTy = $context->getTypeFromString('void');
        $valuePtr = $context->getTypeFromString('__value__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');

        foreach (
            [
                ['__string__separate', $strPtr, [$strPtr]],
                ['__value__writeBool', $voidTy, [$valuePtr, $i8]],
                ['__value__writeHashtable', $voidTy, [$valuePtr, $htPtr]],
                ['strlen', $i64, [$i8p]],
                ['__compiler_trigger_error', $voidTy, [$i8p, $sizeT, $i32, $i8p, $i32]],
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
                throw new \LogicException($name.' missing after StringUnpack bridge (#9543)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
