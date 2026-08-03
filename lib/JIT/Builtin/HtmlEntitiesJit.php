<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value;

/**
 * JIT/AOT link for htmlentities() — HtmlEntitiesJitHelper via thin bridge (#10734, #26417, #26889).
 *
 * Thin AOT NestedJIT of {@see \PHPCompiler\ext\standard\HtmlEntitiesJitHelper} returned a PHP
 * string that is not a native `__string__*` — echo/implode segfault after build (#26889).
 * Peer: {@see StringHtmlspecialchars} / Bin2hex (#20452) bridge shape.
 * SSOT: {@see \PHPCompiler\ext\standard\VmString::htmlentities()}
 * php-src: ext/standard/html.c — php_html_entities()
 */
final class HtmlEntitiesJit
{
    private const ABI = '__string__htmlentities';

    private const HELPER_PATH = '/ext/standard/HtmlEntitiesJitHelper.php';

    private const HELPER_LOGICAL = 'PHPCompiler\\ext\\standard\\HtmlEntitiesJitHelper::encode';

    private const BRIDGE_ENTRY = 'htmlentities_bridge_entry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER_LOGICAL,
    ];

    public static function encode(Context $context, Value $strPtr, Value $flags): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $strPtr,
            $flags
        );
    }

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
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            self::BRIDGE_ENTRY,
            [$strPtr, $i64],
            $strPtr,
            self::HELPER_LOGICAL,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#26889'
        );
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
