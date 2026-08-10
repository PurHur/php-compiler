<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitTempnamKernel;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for tempnam() via TempnamJitHelper PHP (#15685, #27089, #29940).
 *
 * Embed + thin standalone AOT: {@see TempnamJitHelper} via {@see JitVmHelperLink}
 * (sys_get_temp_dir #29433 / gethostname #29364 shape — no always-on libc mkstemp fork).
 * Nested helper compile: `@tempnam` → {@see JitTempnamKernel} mkstemp leaf without
 * re-entering TempnamJitHelper (former thin-AOT always-on kernel #27089).
 * SSOT (VM): {@see \PHPCompiler\ext\standard\VmFsTempnam}.
 * php-src: ext/standard/file.c — php_tempnam
 */
final class StringTempnam
{
    private const ABI = '__phpc_jit_tempnam';

    private const HELPER_PATH = '/ext/standard/TempnamJitHelper.php';

    private const RESOLVE_HELPER = 'PHPCompiler\\ext\\standard\\TempnamJitHelper::resolveArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::RESOLVE_HELPER,
    ];

    private const BRIDGE_ENTRY = 'tempnam_bridge_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $directory, Value $prefix): Value
    {
        if (NestedJitCompileScope::isActive()) {
            // NestedJIT leaf returns `__string__*` (null=fail) — crypt #29545 / getcwd shape.
            return JitTempnamKernel::invoke($context, $directory, $prefix);
        }

        self::ensureLinked($context);

        return $context->builder->call($context->lookupFunction(self::ABI), $directory, $prefix);
    }

    private static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#29940'
        );

        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($valuePtr, false, $strPtr, $strPtr)
            );

        self::emitBridge($context, $fn);
        $context->registerFunction(self::ABI, $fn);

        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function emitBridge(Context $context, LlvmFunction $fn): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::BRIDGE_ENTRY);
        $failBb = $fn->appendBasicBlock('tempnam_bridge_fail');
        $bodyBb = $fn->appendBasicBlock('tempnam_bridge_body');
        $failResultBb = $fn->appendBasicBlock('tempnam_bridge_result_fail');
        $okResultBb = $fn->appendBasicBlock('tempnam_bridge_result_ok');

        $context->builder->positionAtEnd($entry);

        $dir = $fn->getParam(0);
        $pfx = $fn->getParam(1);
        $bad = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $dir, $strPtr->constNull()),
            $context->builder->icmp(Builder::INT_EQ, $pfx, $strPtr->constNull())
        );
        $context->builder->branchIf($bad, $failBb, $bodyBb);

        $context->builder->positionAtEnd($bodyBb);
        $helperFn = JitVmHelperLink::lookupCompiled($context, self::RESOLVE_HELPER, '#29940');
        $pathRaw = JitNestedHelperCoerce::callHelper($context, $helperFn, [$dir, $pfx]);
        $pathNull = JitNestedHelperCoerce::isHelperResultNull($context, $pathRaw);
        $pathStr = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $pathRaw);
        $len = $context->builder->call(
            $context->lookupFunction('strlen'),
            $context->builder->structGep($pathStr, $context->structFieldMap['__string__']['value'])
        );
        $i64 = $context->getTypeFromString('int64');
        $lenI64 = $len->typeOf() === $i64 ? $len : $context->builder->zExt($len, $i64);
        $empty = $context->builder->icmp(Builder::INT_EQ, $lenI64, $i64->constInt(0, false));
        $badResult = $context->builder->or($pathNull, $empty);
        $context->builder->branchIf($badResult, $failResultBb, $okResultBb);

        $context->builder->positionAtEnd($failResultBb);
        $context->builder->branch($failBb);

        $context->builder->positionAtEnd($okResultBb);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $pathStr);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);
        $context->builder->returnValue($ptr);

        $context->builder->positionAtEnd($failBb);
        $failSlot = JitValueBox::alloc($context);
        $failPtr = JitValueBox::pointer($context, $failSlot);
        JitValueBox::writeBool($context, $failSlot, $i1->constInt(0, false));
        $context->builder->returnValue($failPtr);
    }
}
