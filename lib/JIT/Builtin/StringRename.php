<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\ext\standard\JitRenameKernel;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for rename() (#15533, #19215, #20028).
 *
 * Embed / non-thin: {@see RenameJitHelper} via {@see JitVmHelperLink}.
 * Thin standalone AOT main: {@see JitRenameKernel} libc body (#19966 FilePutContents shape).
 * php-src: ext/standard/filestat.c — php_rename
 */
final class StringRename
{
    private const ABI = '__phpc_jit_rename';

    private const HELPER_PATH = '/ext/standard/RenameJitHelper.php';

    private const INVOKE_HELPER = 'PHPCompiler\\ext\\standard\\RenameJitHelper::invokeArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::INVOKE_HELPER,
    ];

    private const BRIDGE_ENTRY = 'rename_bridge_entry';

    private const LIBC_ENTRY = 'rename_libc_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $from, Value $to): Value
    {
        self::ensureLinked($context);

        return $context->builder->call($context->lookupFunction(self::ABI), $from, $to);
    }

    private static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)
            || JitVmHelperLink::hasNamedBridgeEntry($probe, self::LIBC_ENTRY)) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        if ($context->isThinStandaloneAotMain()) {
            self::implementLibcBody($context, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            self::BRIDGE_ENTRY,
            [$strPtr, $strPtr],
            $i1,
            self::INVOKE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#20028'
        );
    }

    private static function implementLibcBody(Context $context, ?LlvmFunction $probe): void
    {
        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        LibcExtern::register($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($i1, false, $strPtr, $strPtr)
            );

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::LIBC_ENTRY);
        $context->builder->positionAtEnd($entry);
        $ok = JitRenameKernel::invoke($context, $fn->getParam(0), $fn->getParam(1));
        $context->builder->returnValue($ok);
        $context->registerFunction(self::ABI, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
