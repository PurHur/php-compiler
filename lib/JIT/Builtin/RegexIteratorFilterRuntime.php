<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * NestedJIT bridge for RegexIterator MATCH filter (#26825).
 */
final class RegexIteratorFilterRuntime
{
    public const ABI = '__compiler_regex_iterator_filter_match';

    private const HELPER_PATH = '/lib/VM/RegexIteratorFilterJitHelper.php';

    private const HELPER = 'PHPCompiler\\VM\\RegexIteratorFilterJitHelper::filterMatch';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        // filterMatch NestedJIT lowers preg_match → `__compiler_preg_match` (#26825).
        // Use StringPregMatch so standalone AOT gets the same prelink as user preg_* (#5289).
        StringPregMatch::ensureLinked($context);

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            'regex_iterator_filter_bridge_entry',
            [$htPtr, $strPtr],
            $htPtr,
            self::HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#26825'
        );
        // NestedJIT may declare `__compiler_preg_match` after our first ensureLinked.
        StringPregMatch::ensureLinked($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI.' missing after RegexIteratorFilterRuntime bridge (#26825)');
        }
        $context->registerFunction(self::ABI, $fn);
    }
}
