<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_sprintf/printf/number_format via SprintfJitHelper PHP (#9131, #20395).
 *
 * Embed / non-thin: NestedJIT {@see \PHPCompiler\ext\standard\SprintfJitHelper} (#9131).
 * Thin standalone AOT (`isThinStandaloneAotMain`, #20380 / #20371 shape): thin stubs without nested JIT (#13137, #13245).
 * php-src: ext/standard/formatted_print.c — sprintf / printf / number_format
 */
final class StringFormat
{
    private const HELPER_PATH = '/ext/standard/SprintfJitHelper.php';

    private const SPRINTF_HELPER = 'PHPCompiler\\ext\\standard\\SprintfJitHelper::sprintfArgv';

    private const NUMBER_FORMAT_HELPER = 'PHPCompiler\\ext\\standard\\SprintfJitHelper::numberFormat';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::SPRINTF_HELPER,
        self::NUMBER_FORMAT_HELPER,
    ];

    /** @var list<string> */
    private const COMPILED_PATHS = [
        '/ext/standard/VmString.php',
        '/ext/standard/Ieee754.php',
        '/ext/standard/VmIni.php',
        '/ext/standard/VmMath.php',
        '/ext/standard/VmRound.php',
        '/ext/standard/VmSerializeFormat.php',
        '/ext/standard/VmFloatDtoa.php',
        '/ext/standard/VmNumberFormat.php',
        '/ext/standard/VmSprintf.php',
        '/ext/standard/PackJitHelper.php',
        self::HELPER_PATH,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_sprintf',
        '__compiler_printf',
        '__compiler_number_format',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    /**
     * User-standalone builds skip ensureLinked at initialize (no nested-JIT
     * stdlib during init, #13571), but printf()/sprintf()/number_format()
     * call sites still declare the ABI symbols — the binary then dies at link
     * with undefined __compiler_sprintf (#15642). Define the bodies on demand
     * at finalize when any ABI symbol is declared but bodyless.
     */
    public static function implementIfDeclared(Context $context, bool $force = false): void
    {
        if ($force) {
            $probe = $context->module->getNamedFunction('__compiler_sprintf');
            if (null !== $probe && $probe->countBasicBlocks() > 0) {
                return;
            }
            // Mid-lowering call site: implement() repositions the builder and
            // clears the insertion point — save and restore the caller's block.
            $savedBlock = null;
            try {
                $savedBlock = $context->builder->getInsertBlock();
            } catch (\Throwable) {
            }
            self::implement($context);
            if (null !== $savedBlock) {
                $context->builder->positionAtEnd($savedBlock);
            }

            return;
        }
        foreach (self::ABI_FUNCTIONS as $abi) {
            $fn = $context->module->getNamedFunction($abi);
            if (null !== $fn && 0 === $fn->countBasicBlocks()) {
                self::implement($context);

                return;
            }
        }
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    /** Thin standalone AOT: linkable sprintf ABI without nested SprintfJitHelper (#13137, #20395). */
    public static function ensureDeferredStubsForInventoryEmit(Context $context): void
    {
        if (!$context->isThinStandaloneAotMain()) {
            return;
        }
        StringFormatInventoryStubs::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction('__compiler_sprintf');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        if ($context->isThinStandaloneAotMain()) {
            StringFormatInventoryStubs::implement($context);

            return;
        }

        self::ensureRuntimeHelpers($context);
        PackArgvSerialize::ensureLinked($context);
        self::ensureJitHelperCompiled($context);
        self::implementSprintfBridge($context);
        self::implementPrintfBridge($context);
        self::implementNumberFormatBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementSprintfBridge(Context $context): void
    {
        $abiName = '__compiler_sprintf';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $valuePtr = $context->getTypeFromString('__value__*');
        $ft = $context->context->functionType($strPtr, false, $strPtr, $i64, $valuePtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('sprintf_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $fmt = $fn->getParam(0);
        $argc = $fn->getParam(1);
        $argv = $fn->getParam(2);
        $fmtSep = $context->builder->call($context->lookupFunction('__string__separate'), $fmt);
        $blob = $context->builder->call(
            $context->lookupFunction('phpc_pack_argv_serialize'),
            $argc,
            $argv
        );
        $out = $context->builder->call(
            self::helperFunction($context, self::SPRINTF_HELPER),
            $fmtSep,
            $blob
        );
        $context->builder->returnValue($out);
        $context->registerFunction($abiName, $fn);
    }

    private static function implementPrintfBridge(Context $context): void
    {
        $abiName = '__compiler_printf';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $valuePtr = $context->getTypeFromString('__value__*');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $zeroI64 = $i64->constInt(0, false);
        $ft = $context->context->functionType($i64, false, $strPtr, $i64, $valuePtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('printf_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $fmt = $fn->getParam(0);
        $argc = $fn->getParam(1);
        $argv = $fn->getParam(2);

        $sprintfFn = $context->lookupFunction('__compiler_sprintf');
        $echoFn = $context->lookupFunction('__phpc_ob_echo_substr');
        $out = $context->builder->call($sprintfFn, $fmt, $argc, $argv);

        $nullOut = $fn->appendBasicBlock('printf_null_out');
        $work = $fn->appendBasicBlock('printf_work');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $out, $strPtr->constNull()),
            $nullOut,
            $work
        );

        $context->builder->positionAtEnd($nullOut);
        $context->builder->returnValue($zeroI64);

        $stringMap = $context->structFieldMap['__string__'];
        $context->builder->positionAtEnd($work);
        $data = $context->builder->structGep($out, $stringMap['value']);
        $len = $context->builder->load($context->builder->structGep($out, $stringMap['length']));
        $shouldEcho = $context->builder->and(
            $context->builder->icmp(Builder::INT_UGT, $len, $sizeT->constInt(0, false)),
            $context->builder->icmp(Builder::INT_NE, $data, $i8p->constNull())
        );
        $echoBb = $fn->appendBasicBlock('printf_echo');
        $done = $fn->appendBasicBlock('printf_done');
        $context->builder->branchIf($shouldEcho, $echoBb, $done);

        $context->builder->positionAtEnd($echoBb);
        $context->builder->call($echoFn, $data, $len);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $context->builder->returnValue($context->builder->zExt($len, $i64));
        $context->registerFunction($abiName, $fn);
    }

    private static function implementNumberFormatBridge(Context $context): void
    {
        $abiName = '__compiler_number_format';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $double = $context->getTypeFromString('double');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($strPtr, false, $double, $i64, $strPtr, $strPtr, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('number_format_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $num = $fn->getParam(0);
        $decimals = $fn->getParam(1);
        $decSep = $context->builder->call($context->lookupFunction('__string__separate'), $fn->getParam(2));
        $thouSep = $context->builder->call($context->lookupFunction('__string__separate'), $fn->getParam(3));
        $mode = $fn->getParam(4);
        $out = $context->builder->call(
            self::helperFunction($context, self::NUMBER_FORMAT_HELPER),
            $num,
            $decimals,
            $decSep,
            $thouSep,
            $mode
        );
        $context->builder->returnValue($out);
        $context->registerFunction($abiName, $fn);
    }

    private static function ensureRuntimeHelpers(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');

        foreach (
            [
                ['__string__separate', $strPtr, [$strPtr]],
                ['__phpc_ob_echo_substr', $context->getTypeFromString('void'), [
                    $context->getTypeFromString('int8*'),
                    $context->getTypeFromString('size_t'),
                ]],
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

        return JitVmHelperLink::lookupCompiled($context, $logical, '#9131');
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
        // Cache first: with PHP_COMPILER_HELPER_RUNTIME_O=1 the sprintf unit
        // binds as extern declarations + a prebuilt unit.o — no nested corpus
        // compile (which OOMs a default-memory user build, #15642).
        if (\PHPCompiler\AOT\HelperRuntimeCache::tryProvide($context, self::COMPILED_HELPERS)) {
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
        }

        $runtime = $context->runtime;
        $root = \dirname(__DIR__, 3);
        $paths = \array_map(static fn (string $rel): string => $root.$rel, self::COMPILED_PATHS);
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $paths): void {
            $jit = new JIT($context);
            foreach ($paths as $includePath) {
                $real = \realpath($includePath) ?: $includePath;
                if ($context->hasJitIncludedFileCompiled($real)) {
                    continue;
                }
                $block = $runtime->parseAndCompile(
                    (string) \file_get_contents($includePath),
                    \basename($includePath)
                );
                if (null === $block) {
                    throw new \LogicException(\basename($includePath).' parseAndCompile failed (#9131)');
                }
                $jit->compile($block);
                $context->markJitIncludedFileCompiled($real);
            }
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            JitVmHelperLink::lookupCompiled($context, $logical, '#9131');
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringFormat bridge (#9131)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
