<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_pack via PackJitHelper PHP (#9133, #13062).
 *
 * JIT and standalone AOT both use compiled {@see PackJitHelper} + {@see PackEngine}.
 * php-src: ext/standard/pack.c
 */
final class StringPack
{
    private const HELPER_PATH = '/ext/standard/PackJitHelper.php';

    private const PACK_HELPER = 'PHPCompiler\\ext\\standard\\PackJitHelper::packArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::PACK_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_pack',
    ];

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
        $probe = $context->module->getNamedFunction('__compiler_pack');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureRuntimeHelpers($context);
        PackArgvSerialize::ensureLinked($context);
        self::ensureJitHelperCompiled($context);
        self::implementPackBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementPackBridge(Context $context): void
    {
        $abiName = '__compiler_pack';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $valuePtr = $context->getTypeFromString('__value__*');
        $i8p = $context->getTypeFromString('int8*');
        $ft = $context->context->functionType($strPtr, false, $strPtr, $i64, $valuePtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('pack_bridge_entry');
        $fmtNull = $fn->appendBasicBlock('pack_bridge_fmt_null');
        $emptyFmt = $fn->appendBasicBlock('pack_bridge_empty_fmt');
        $packBody = $fn->appendBasicBlock('pack_bridge_body');

        $context->builder->positionAtEnd($entry);
        $fmt = $fn->getParam(0);
        $argc = $fn->getParam(1);
        $argv = $fn->getParam(2);
        $nullFmt = $context->builder->icmp(Builder::INT_EQ, $fmt, $strPtr->constNull());
        $context->builder->branchIf($nullFmt, $fmtNull, $packBody);

        $context->builder->positionAtEnd($fmtNull);
        TypeErrorRaise::ensureLinked($context);
        $msg = $context->builder->pointerCast(
            $context->constantFromString('pack(): Argument #1 ($format) must be of type string'),
            $i8p
        );
        $msgLen = $context->builder->call($context->lookupFunction('strlen'), $msg);
        $sizeT = $context->getTypeFromString('size_t');
        $context->builder->call(
            $context->lookupFunction('__compiler_jit_raise_value_error'),
            $msg,
            $context->builder->intCast($msgLen, $sizeT)
        );
        $empty = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(0, false),
            $context->builder->pointerCast($context->constantFromString(''), $i8p)
        );
        $context->builder->returnValue($empty);

        $serializeBb = $fn->appendBasicBlock('pack_bridge_serialize');

        $context->builder->positionAtEnd($packBody);
        $fmtSep = $context->builder->call($context->lookupFunction('__string__separate'), $fmt);
        $stringMap = $context->structFieldMap['__string__'];
        $formatLen = $context->builder->load($context->builder->structGep($fmtSep, $stringMap['length']));
        $zeroI64 = $i64->constInt(0, false);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $formatLen, $zeroI64),
            $emptyFmt,
            $serializeBb
        );

        $context->builder->positionAtEnd($emptyFmt);
        $context->builder->returnValue($context->builder->call(
            $context->lookupFunction('__string__init'),
            $zeroI64,
            $context->builder->pointerCast($context->constantFromString(''), $i8p)
        ));

        $context->builder->positionAtEnd($serializeBb);
        $blob = $context->builder->call(
            $context->lookupFunction('phpc_pack_argv_serialize'),
            $argc,
            $argv
        );
        $packed = $context->builder->call(
            self::helperFunction($context, self::PACK_HELPER),
            $fmtSep,
            $blob
        );
        $context->builder->returnValue($packed);
        $context->registerFunction($abiName, $fn);
    }

    private static function ensureRuntimeHelpers(Context $context): void
    {
        TypeErrorRaise::ensureLinked($context);
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');

        foreach (
            [
                ['__string__separate', $strPtr, [$strPtr]],
                ['__string__init', $strPtr, [$i64, $i8p]],
                ['strlen', $i64, [$i8p]],
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

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after PackJitHelper compile (#9133)');
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
        $path = $root.self::HELPER_PATH;
        $enginePath = $root.'/ext/standard/PackJitEngine.php';
        $ieeePath = $root.'/ext/standard/Ieee754.php';
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path, $enginePath, $ieeePath): void {
            $jit = new JIT($context);
            foreach ([$ieeePath, $enginePath, $path] as $includePath) {
                $real = \realpath($includePath) ?: $includePath;
                if ($context->hasJitIncludedFileCompiled($real)) {
                    continue;
                }
                $block = $runtime->parseAndCompile(
                    (string) \file_get_contents($includePath),
                    \basename($includePath)
                );
                if (null === $block) {
                    throw new \LogicException(\basename($includePath).' parseAndCompile failed (#9133)');
                }
                $jit->compile($block);
                $context->markJitIncludedFileCompiled($real);
            }
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                throw new \LogicException($logical.' was not compiled for JIT (#9133)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringPack bridge (#9133)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
