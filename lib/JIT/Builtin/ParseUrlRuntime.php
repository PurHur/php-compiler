<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __phpc_parse_url_* via ParseUrlJitHelper PHP (#9358).
 *
 * Replaces {@see ParseUrlJit} LLVM (~1.2k LOC). SSOT: {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/url.c — php_parse_url()
 */
final class ParseUrlRuntime
{
    private const HELPER_PATH = '/ext/standard/ParseUrlJitHelper.php';

    private const COMPONENT_HELPER = 'PHPCompiler\\ext\\standard\\ParseUrlJitHelper::parseUrlComponent';

    private const ASSOC_HELPER = 'PHPCompiler\\ext\\standard\\ParseUrlJitHelper::parseUrlAssoc';

    private const LAST_STRING = 'PHPCompiler\\ext\\standard\\ParseUrlJitHelper::lastString';

    private const LAST_INT = 'PHPCompiler\\ext\\standard\\ParseUrlJitHelper::lastInt';

    private const TAG_FALSE = 0;

    private const TAG_NULL = 1;

    private const TAG_STRING = 2;

    private const TAG_INT = 3;

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::COMPONENT_HELPER,
        self::ASSOC_HELPER,
        self::LAST_STRING,
        self::LAST_INT,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__phpc_parse_url_component',
        '__phpc_parse_url_assoc',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__phpc_parse_url_component');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureJitHelperCompiled($context);
        self::implementIfMissing($context, '__phpc_parse_url_component', self::implementComponentBridge(...));
        self::implementIfMissing($context, '__phpc_parse_url_assoc', self::implementAssocBridge(...));
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
        $voidTy = $context->getTypeFromString('void');
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $valuePtr = $context->getTypeFromString('__value__*');

        return match ($name) {
            '__phpc_parse_url_component' => $context->module->addFunction(
                $name,
                $context->context->functionType($voidTy, false, $strPtr, $i64, $valuePtr)
            ),
            '__phpc_parse_url_assoc' => $context->module->addFunction(
                $name,
                $context->context->functionType($voidTy, false, $strPtr, $valuePtr)
            ),
            default => throw new \LogicException('Unknown parse_url JIT helper: '.$name),
        };
    }

    private static function implementComponentBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('puc_bridge_entry');
        $nullOutBb = $fn->appendBasicBlock('puc_null_out');
        $bodyBb = $fn->appendBasicBlock('puc_body');
        $context->builder->positionAtEnd($entry);

        $url = $fn->getParam(0);
        $component = $fn->getParam(1);
        $out = $fn->getParam(2);
        $valuePtr = $context->getTypeFromString('__value__*');
        $nullOut = $context->builder->icmp(Builder::INT_EQ, $out, $valuePtr->constNull());
        $context->builder->branchIf($nullOut, $nullOutBb, $bodyBb);

        $context->builder->positionAtEnd($nullOutBb);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($bodyBb);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $tag = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::COMPONENT_HELPER),
            [$url, $context->builder->trunc($component, $i32)]
        );
        $tagI32 = $context->builder->trunc($tag, $i32);

        $falseBb = BasicBlockHelper::append($context, 'puc_tag_false');
        $nullTagBb = BasicBlockHelper::append($context, 'puc_tag_null');
        $stringBb = BasicBlockHelper::append($context, 'puc_tag_string');
        $intBb = BasicBlockHelper::append($context, 'puc_tag_int');
        $doneBb = BasicBlockHelper::append($context, 'puc_done');
        $checkNullBb = BasicBlockHelper::append($context, 'puc_check_null');
        $checkStringBb = BasicBlockHelper::append($context, 'puc_check_string');

        $isFalse = $context->builder->icmp(Builder::INT_EQ, $tagI32, $i32->constInt(self::TAG_FALSE, false));
        $context->builder->branchIf($isFalse, $falseBb, $checkNullBb);

        $context->builder->positionAtEnd($falseBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $out,
            $i32->constInt(0, false)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($checkNullBb);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $tagI32, $i32->constInt(self::TAG_NULL, false));
        $context->builder->branchIf($isNull, $nullTagBb, $checkStringBb);

        $context->builder->positionAtEnd($nullTagBb);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $out);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($checkStringBb);
        $isString = $context->builder->icmp(Builder::INT_EQ, $tagI32, $i32->constInt(self::TAG_STRING, false));
        $context->builder->branchIf($isString, $stringBb, $intBb);

        $context->builder->positionAtEnd($stringBb);
        $strResult = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::LAST_STRING),
            []
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $out,
            $strResult
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($intBb);
        $intResult = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::LAST_INT),
            []
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $out,
            $context->builder->sext($context->builder->trunc($intResult, $i32), $i64)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();
    }

    private static function implementAssocBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('pua_bridge_entry');
        $nullOutBb = $fn->appendBasicBlock('pua_null_out');
        $bodyBb = $fn->appendBasicBlock('pua_body');
        $context->builder->positionAtEnd($entry);

        $url = $fn->getParam(0);
        $out = $fn->getParam(1);
        $valuePtr = $context->getTypeFromString('__value__*');
        $nullOut = $context->builder->icmp(Builder::INT_EQ, $out, $valuePtr->constNull());
        $context->builder->branchIf($nullOut, $nullOutBb, $bodyBb);

        $context->builder->positionAtEnd($nullOutBb);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($bodyBb);
        $i32 = $context->getTypeFromString('int32');
        $htRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::ASSOC_HELPER),
            [$url]
        );
        $htNull = JitNestedHelperCoerce::isHelperResultNull($context, $htRaw);
        $falseBb = BasicBlockHelper::append($context, 'pua_false');
        $storeBb = BasicBlockHelper::append($context, 'pua_store');
        $doneBb = BasicBlockHelper::append($context, 'pua_done');
        $context->builder->branchIf($htNull, $falseBb, $storeBb);

        $context->builder->positionAtEnd($falseBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $out,
            $i32->constInt(0, false)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($storeBb);
        $ht = JitNestedHelperCoerce::coerceToHashtablePtr($context, $htRaw);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $out,
            $ht
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after ParseUrlJitHelper compile (#9358)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $missing = false;
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'ParseUrlJitHelper.php');
            if (null === $block) {
                throw new \LogicException('ParseUrlJitHelper.php parseAndCompile failed (#9358)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9358)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after ParseUrlRuntime bridge (#9358)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
