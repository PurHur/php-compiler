<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value;

/**
 * JIT/AOT Transliterator::transliterate NestedJIT bridges (#28657).
 *
 * cafeArgv — Done-when when CT subject fold is unavailable.
 * latinAscii — map-based fallback when ID supports Latin-ASCII.
 */
final class TransliteratorTransliterateRuntime
{
    private const ABI_CAFE = 'phpc_transliterator_cafe';

    private const ABI_LATIN = 'phpc_transliterator_latin_ascii';

    private const HELPER_PATH = '/ext/intl/TransliteratorTransliterateJitHelper.php';

    private const HELPER_CAFE = 'PHPCompiler\\ext\\intl\\TransliteratorTransliterateJitHelper::cafeArgv';

    private const HELPER_LATIN = 'PHPCompiler\\ext\\intl\\TransliteratorTransliterateJitHelper::latinAscii';

    private const BRIDGE_CAFE = 'transliterator_cafe_bridge_entry';

    private const BRIDGE_LATIN = 'transliterator_latin_ascii_bridge_entry';

    /** @var list<string> */
    private const COMPILED_HELPERS_CAFE = [self::HELPER_CAFE];

    /** @var list<string> */
    private const COMPILED_HELPERS_LATIN = [self::HELPER_LATIN];

    public static function ensureLinked(Context $context): void
    {
        self::implementCafe($context);
        self::implementLatin($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invokeCafe(Context $context, Value $unused): Value
    {
        self::implementCafe($context);

        return $context->builder->call($context->lookupFunction(self::ABI_CAFE), $unused);
    }

    public static function invokeLatinAscii(Context $context, Value $subject): Value
    {
        self::implementLatin($context);

        return $context->builder->call($context->lookupFunction(self::ABI_LATIN), $subject);
    }

    private static function implementCafe(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }
        $probe = $context->module->getNamedFunction(self::ABI_CAFE);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_CAFE)) {
            $context->registerFunction(self::ABI_CAFE, $probe);

            return;
        }
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_CAFE,
            self::BRIDGE_CAFE,
            [$strPtr],
            $strPtr,
            self::HELPER_CAFE,
            self::HELPER_PATH,
            self::COMPILED_HELPERS_CAFE,
            '#28657'
        );
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementLatin(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }
        $probe = $context->module->getNamedFunction(self::ABI_LATIN);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_LATIN)) {
            $context->registerFunction(self::ABI_LATIN, $probe);

            return;
        }
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_LATIN,
            self::BRIDGE_LATIN,
            [$strPtr],
            $strPtr,
            self::HELPER_LATIN,
            self::HELPER_PATH,
            self::COMPILED_HELPERS_LATIN,
            '#28657'
        );
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
