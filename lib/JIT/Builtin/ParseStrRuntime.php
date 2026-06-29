<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_parse_str via ParseStrJitHelper PHP (#9295).
 *
 * Replaces legacy LLVM parse_str lowering; superglobals refresh uses
 * {@see \PHPCompiler\Web\SuperglobalRefreshJitHelper} PHP (#9907, #13429).
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(parse_str)
 */
final class ParseStrRuntime
{
    private const HELPER_PATH = '/ext/standard/ParseStrJitHelper.php';

    private const PARSE_INTO_HELPER = 'PHPCompiler\\ext\\standard\\ParseStrJitHelper::parseInto';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::PARSE_INTO_HELPER,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_parse_str',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_parse_str');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#9295'
        );
        self::implementIfMissing($context, '__compiler_parse_str', self::implementParseBridge(...));
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * @param callable(Context, LlvmFunction): void $emit
     */
    private static function implementIfMissing(Context $context, string $name, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $fn = self::declareFunction($context, $name);
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $void = $context->getTypeFromString('void');

        return $context->module->addFunction(
            $name,
            $context->context->functionType($void, false, $htPtr, $strPtr)
        );
    }

    private static function implementParseBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('parse_str_bridge_entry');
        // Append on $fn directly — defineBuiltins() has no insert block yet (#1492 inventory argv).
        $early = $fn->appendBasicBlock('parse_str_bridge_early');
        $work = $fn->appendBasicBlock('parse_str_bridge_work');
        $context->builder->positionAtEnd($entry);

        $dest = $fn->getParam(0);
        $encoded = $fn->getParam(1);
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $nullDest = $context->builder->icmp(Builder::INT_EQ, $dest, $htPtr->constNull());
        $context->builder->branchIf($nullDest, $early, $work);

        $context->builder->positionAtEnd($early);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($work);
        $helperFn = JitVmHelperLink::lookupCompiled($context, self::PARSE_INTO_HELPER, '#9295');
        $destArg = JitNestedHelperCoerce::coerceArgForHelper(
            $context,
            $dest,
            $helperFn->getParam(0)->typeOf()
        );
        $encodedArg = JitNestedHelperCoerce::coerceArgForHelper(
            $context,
            $encoded,
            $helperFn->getParam(1)->typeOf()
        );
        $context->builder->call($helperFn, $destArg, $encodedArg);
        $context->builder->returnVoid();
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after ParseStrRuntime bridge (#9295)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
