<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitStrxfrm;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for strxfrm() via StrxfrmJitHelper PHP (#30420).
 *
 * Embed + thin standalone AOT: {@see StrxfrmJitHelper} via {@see JitVmHelperLink}
 * (nl_langinfo #30404 / fnmatch #30383 shape — `__string__*` ABI).
 * Nested helper compile: `\strxfrm` → {@see JitStrxfrm} thin libc leaf.
 * php-src: ext/standard/string.c — PHP_FUNCTION(strxfrm)
 */
final class StringStrxfrm
{
    private const HELPER_PATH = '/ext/standard/StrxfrmJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\standard\\StrxfrmJitHelper::strxfrmArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER,
    ];

    private const ABI = '__compiler_strxfrm';

    private const BRIDGE_ENTRY = 'strxfrm_bridge_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    /** @return Value `__string__*` */
    public static function invoke(Context $context, JITVariable $string): Value
    {
        if (NestedJitCompileScope::isActive()) {
            return JitStrxfrm::invokeLibcLeaf($context, $string);
        }

        self::ensureLinked($context);
        $srcStr = JitStringBuiltinArg::lower($context, $string, 'strxfrm', 0, 'string');

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $srcStr
        );
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
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            self::BRIDGE_ENTRY,
            [$strPtr],
            $strPtr,
            self::HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#30420'
        );
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
    }
}
