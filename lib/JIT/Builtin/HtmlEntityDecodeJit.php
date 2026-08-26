<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value;

/**
 * JIT/AOT link for html_entity_decode() via thin `__string__*` bridge (#4130, #26441, #35069).
 *
 * Direct NestedJIT of {@see \PHPCompiler\ext\standard\HtmlEntityDecodeJitHelper} returns a PHP
 * string that is not a native `__string__*` — echo segfaults after AOT (#26889 peer).
 *
 * Prior `__compiler_html_entity_decode_dispatch` cleared the insert block (Module::verify abort)
 * and routed non-HTML5 flags through `__string__htmlspecialchars_decode` (`&eacute;` stayed
 * literal). Always bridge to {@see HtmlEntityDecodeJitHelper::decode}.
 *
 * SSOT: {@see \PHPCompiler\ext\standard\VmString::html_entity_decode()}
 * php-src: ext/standard/html.c — php_unescape_html_entities()
 */
final class HtmlEntityDecodeJit
{
    private const ABI = '__string__html_entity_decode';

    private const ABI_EX = '__string__html_entity_decode_ex';

    private const HELPER_PATH = '/ext/standard/HtmlEntityDecodeJitHelper.php';

    private const HELPER_LOGICAL = 'PHPCompiler\\ext\\standard\\HtmlEntityDecodeJitHelper::decode';

    private const HELPER_ENCODING_LOGICAL = 'PHPCompiler\\ext\\standard\\HtmlEntityDecodeJitHelper::decodeWithEncoding';

    private const BRIDGE_ENTRY = 'html_entity_decode_bridge_entry';

    private const BRIDGE_ENTRY_EX = 'html_entity_decode_ex_bridge_entry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER_LOGICAL,
        self::HELPER_ENCODING_LOGICAL,
    ];

    public static function decode(Context $context, Value $strPtr, Value $flags): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $strPtr,
            $flags
        );
    }

    public static function decodeWithEncoding(
        Context $context,
        Value $strPtr,
        Value $flags,
        Value $encodingPtr
    ): Value {
        self::ensureLinkedEx($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_EX),
            $strPtr,
            $flags,
            $encodingPtr
        );
    }

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureLinkedEx(Context $context): void
    {
        self::implementEx($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
        self::implementEx($context);
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
            '#35069'
        );
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    public static function implementEx(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_EX);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY_EX)) {
            $context->registerFunction(self::ABI_EX, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_EX,
            self::BRIDGE_ENTRY_EX,
            [$strPtr, $i64, $strPtr],
            $strPtr,
            self::HELPER_ENCODING_LOGICAL,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#35069'
        );
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
