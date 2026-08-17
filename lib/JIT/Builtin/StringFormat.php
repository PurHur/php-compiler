<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_sprintf/printf/number_format via SprintfJitHelper PHP (#9131, #20841).
 *
 * Embed + thin standalone AOT: NestedJIT {@see \PHPCompiler\ext\standard\SprintfJitHelper}
 * via {@see JitVmHelperLink} (Bin2hex #20452 / HashEquals #20469 — no thin identity stubs).
 * php-src: ext/standard/formatted_print.c — sprintf / printf / number_format
 */
final class StringFormat
{
    private const HELPER_PATH = '/ext/standard/SprintfJitHelper.php';

    private const SPRINTF_HELPER = 'PHPCompiler\\ext\\standard\\SprintfJitHelper::sprintfArgv';

    private const SPRINTF_BRIDGE_ENTRY = 'sprintf_bridge_entry';

    private const PRINTF_BRIDGE_ENTRY = 'printf_bridge_entry';

    private const NUMBER_FORMAT_BRIDGE_ENTRY = 'number_format_bridge_entry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::SPRINTF_HELPER,
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

    public static function implementIfDeclared(Context $context, bool $force = false): void
    {
        if ($force) {
            $probe = $context->module->getNamedFunction('__compiler_sprintf');
            if (null !== $probe && $probe->countBasicBlocks() > 0) {
                return;
            }
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

    public static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);

        $sprintfProbe = $context->module->getNamedFunction('__compiler_sprintf');
        $printfProbe = $context->module->getNamedFunction('__compiler_printf');
        $numberProbe = $context->module->getNamedFunction('__compiler_number_format');
        if (JitVmHelperLink::hasNamedBridgeEntry($sprintfProbe, self::SPRINTF_BRIDGE_ENTRY)
            && JitVmHelperLink::hasNamedBridgeEntry($printfProbe, self::PRINTF_BRIDGE_ENTRY)
            && JitVmHelperLink::hasNamedBridgeEntry($numberProbe, self::NUMBER_FORMAT_BRIDGE_ENTRY)) {
            self::registerLinkedRuntime($context);
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);

            return;
        }
        if (null !== $sprintfProbe && $sprintfProbe->countBasicBlocks() > 0
            && null !== $printfProbe && $printfProbe->countBasicBlocks() > 0
            && null !== $numberProbe && $numberProbe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);

            return;
        }

        self::ensureRuntimeHelpers($context);
        PackArgvSerialize::ensureLinked($context);
        self::ensureSprintfJitHelperCompiled($context);
        self::implementSprintfBridge($context);
        self::implementPrintfBridge($context);
        self::implementNumberFormatBridge($context);
        self::registerLinkedRuntime($context);
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
    }

    private static function implementSprintfBridge(Context $context): void
    {
        $abiName = '__compiler_sprintf';
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::SPRINTF_BRIDGE_ENTRY)) {
            $context->registerFunction($abiName, $probe);

            return;
        }
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $valuePtr = $context->getTypeFromString('__value__*');
        $ft = $context->context->functionType($strPtr, false, $strPtr, $i64, $valuePtr);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock(self::SPRINTF_BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $fmtSep = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $fn->getParam(0)
        );
        $argc = $fn->getParam(1);
        $argv = $fn->getParam(2);
        $one = $i64->constInt(1, false);
        $fastBb = $fn->appendBasicBlock('sprintf_one_arg_fast');
        $slowBb = $fn->appendBasicBlock('sprintf_nestedjit_slow');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $argc, $one),
            $fastBb,
            $slowBb
        );

        $context->builder->positionAtEnd($fastBb);
        $out = SprintfSnprintfRuntime::formatOneArg($context, $fn, $fmtSep, $argv);
        $context->builder->returnValue($out);

        $context->builder->positionAtEnd($slowBb);
        $blob = $context->builder->call(
            $context->lookupFunction('phpc_pack_argv_serialize'),
            $argc,
            $argv
        );
        $helper = self::sprintfHelperFunction($context, self::SPRINTF_HELPER);
        $out = JitNestedHelperCoerce::callHelper($context, $helper, [$fmtSep, $blob]);
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $out, $strPtr)
        );
        $context->registerFunction($abiName, $fn);
    }

    private static function implementPrintfBridge(Context $context): void
    {
        $abiName = '__compiler_printf';
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::PRINTF_BRIDGE_ENTRY)) {
            $context->registerFunction($abiName, $probe);

            return;
        }
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
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock(self::PRINTF_BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $out = $context->builder->call(
            $context->lookupFunction('__compiler_sprintf'),
            $fn->getParam(0),
            $fn->getParam(1),
            $fn->getParam(2)
        );

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
        $context->builder->call($context->lookupFunction('__phpc_ob_echo_substr'), $data, $len);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
        $context->builder->returnValue($context->builder->zExt($len, $i64));
        $context->registerFunction($abiName, $fn);
    }

    private static function implementNumberFormatBridge(Context $context): void
    {
        $abiName = '__compiler_number_format';
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::NUMBER_FORMAT_BRIDGE_ENTRY)) {
            $context->registerFunction($abiName, $probe);

            return;
        }
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $double = $context->getTypeFromString('double');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($strPtr, false, $double, $i64, $strPtr, $strPtr, $i64);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock(self::NUMBER_FORMAT_BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $thouSep = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $fn->getParam(3)
        );
        $thouOrd = self::stringFirstByteOrd($context, $fn, $thouSep);
        NumberFormatRuntime::emitBridgeBody($context, $fn, $thouOrd);
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

    private static function sprintfHelperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureSprintfJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#20841');
    }

    public static function ensureSprintfJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#20841'
        );
    }

    /** @deprecated use ensureSprintfJitHelperCompiled */
    public static function ensureJitHelperCompiled(Context $context): void
    {
        self::ensureSprintfJitHelperCompiled($context);
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringFormat bridge (#20841)');
            }
            $context->registerFunction($name, $fn);
        }
    }

    /** First UTF-8 byte of separated __string__ (0 when empty) for NestedJIT separator ordinals (#31963). */
    private static function stringFirstByteOrd(Context $context, LlvmFunction $fn, \PHPLLVM\Value $strSep): \PHPLLVM\Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $sizeT = $context->getTypeFromString('size_t');
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load($context->builder->structGep($strSep, $map['length']));
        $data = $context->builder->structGep($strSep, $map['value']);
        $emptyBb = $fn->appendBasicBlock('nf_sep_ord_empty');
        $workBb = $fn->appendBasicBlock('nf_sep_ord_work');
        $doneBb = $fn->appendBasicBlock('nf_sep_ord_done');
        $hasByte = $context->builder->icmp(Builder::INT_UGT, $len, $sizeT->constInt(0, false));
        $context->builder->branchIf($hasByte, $workBb, $emptyBb);
        $context->builder->positionAtEnd($emptyBb);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($workBb);
        $byte = $context->builder->load($data);
        $ord = $context->builder->zExt($byte, $i64);
        $workEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($i64);
        $phi->addIncoming($i64->constInt(0, false), $emptyBb);
        $phi->addIncoming($ord, $workEnd);

        return $phi;
    }
}
