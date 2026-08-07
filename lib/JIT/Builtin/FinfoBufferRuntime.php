<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value;

/**
 * JIT/AOT link for finfo_buffer() / finfo::buffer() via FinfoFileJitHelper (#28660).
 *
 * Helper compile: {@see JitVmHelperLink::ensureBridge} (peer {@see FinfoFileRuntime}).
 * SSOT sniff: {@see \PHPCompiler\ext\fileinfo\FinfoFileJitHelper::detectFromBytes}
 * php-src: ext/fileinfo/fileinfo.c — PHP_FUNCTION(finfo_buffer)
 */
final class FinfoBufferRuntime
{
    private const ABI = 'phpc_finfo_buffer_mime';

    private const HELPER_PATH = '/ext/fileinfo/FinfoFileJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\fileinfo\\FinfoFileJitHelper::mimeFromBuffer';

    private const BRIDGE_ENTRY = 'finfo_buffer_mime_bridge_entry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function invoke(Context $context, Value $bufferStr): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $bufferStr
        );
    }

    public static function implement(Context $context): void
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
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            self::BRIDGE_ENTRY,
            [$strPtr],
            $strPtr,
            self::HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#28660'
        );
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
