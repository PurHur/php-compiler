<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __phpc_parse_url_* via ParseUrlJitHelper PHP (#9358, #22861, #27078, #33226, #33236).
 *
 * Assoc HT: {@see ParseUrlAssocLlvm} (component + lastString/lastInt).
 * Thin AOT call-site {@see ensureLinked} must {@see BasicBlockHelper::scopeLoweringToFunction}
 * so bridge blocks are not appended to user main (#27211 / Module.php:180).
 * Do not re-add always-on empty decls in {@see Type} — leftover decls mint parse_url_component.1
 * (#31894 / #32122 / #33236). SSOT: {@see \PHPCompiler\ext\standard\VmString}. php-src: ext/standard/url.c
 */
final class ParseUrlRuntime
{
    private const HELPER_PATH = '/ext/standard/ParseUrlJitHelper.php';

    private const COMPONENT_HELPER = 'PHPCompiler\\ext\\standard\\ParseUrlJitHelper::parseUrlComponent';

    private const LAST_STRING = 'PHPCompiler\\ext\\standard\\ParseUrlJitHelper::lastString';

    private const LAST_INT = 'PHPCompiler\\ext\\standard\\ParseUrlJitHelper::lastInt';

    private const TAG_FALSE = 0;

    private const TAG_NULL = 1;

    private const TAG_STRING = 2;

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::COMPONENT_HELPER,
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
        self::ensureHashtableHelpers($context);
        self::implementIfMissing($context, '__phpc_parse_url_component', self::implementComponentBridge(...));
        self::implementIfMissing($context, '__phpc_parse_url_assoc', ParseUrlAssocLlvm::implement(...));
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /** @param callable(Context, LlvmFunction): void $emit */
    private static function implementIfMissing(Context $context, string $name, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }
        $fn = self::declareFunction($context, $name);
        // Mid-invoke ensureLinked: loweringLlvmFunction is the user fn (#33226 / #27211).
        BasicBlockHelper::scopeLoweringToFunction($context, $fn, $name, static function () use ($context, $fn, $emit): void {
            $emit($context, $fn);
        });
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        // getNamedFunction first — leftover Type empty decls + blind addFunction mint .1 (#31894 / #33236).
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe) {
            $context->registerFunction($name, $probe);

            return $probe;
        }
        try {
            return $context->lookupFunction($name);
        } catch (\Throwable) {
        }
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
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $out, $valuePtr->constNull()),
            $nullOutBb,
            $bodyBb
        );
        $context->builder->positionAtEnd($nullOutBb);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($bodyBb);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $tagI32 = $context->builder->trunc(
            JitNestedHelperCoerce::callHelper(
                $context,
                self::helperFunction($context, self::COMPONENT_HELPER),
                [$url, $context->builder->trunc($component, $i32)]
            ),
            $i32
        );
        $falseBb = BasicBlockHelper::append($context, 'puc_tag_false');
        $nullTagBb = BasicBlockHelper::append($context, 'puc_tag_null');
        $stringBb = BasicBlockHelper::append($context, 'puc_tag_string');
        $intBb = BasicBlockHelper::append($context, 'puc_tag_int');
        $doneBb = BasicBlockHelper::append($context, 'puc_done');
        $checkNullBb = BasicBlockHelper::append($context, 'puc_check_null');
        $checkStringBb = BasicBlockHelper::append($context, 'puc_check_string');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $tagI32, $i32->constInt(self::TAG_FALSE, false)),
            $falseBb,
            $checkNullBb
        );
        $context->builder->positionAtEnd($falseBb);
        $context->builder->call($context->lookupFunction('__value__writeBool'), $out, $i32->constInt(0, false));
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($checkNullBb);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $tagI32, $i32->constInt(self::TAG_NULL, false)),
            $nullTagBb,
            $checkStringBb
        );
        $context->builder->positionAtEnd($nullTagBb);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $out);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($checkStringBb);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $tagI32, $i32->constInt(self::TAG_STRING, false)),
            $stringBb,
            $intBb
        );
        $context->builder->positionAtEnd($stringBb);
        $strRaw = JitNestedHelperCoerce::callHelper($context, self::helperFunction($context, self::LAST_STRING), []);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $out,
            JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $strRaw)
        );
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($intBb);
        $intResult = JitNestedHelperCoerce::callHelper($context, self::helperFunction($context, self::LAST_INT), []);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $out,
            $context->builder->sext($context->builder->trunc($intResult, $i32), $i64)
        );
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#22861');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, '#22861');
    }

    private static function ensureHashtableHelpers(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $void = $context->getTypeFromString('void');
        self::ensureExternal($context, '__hashtable__alloc', $context->context->functionType($htPtr, false));
        self::ensureExternal(
            $context,
            '__hashtable__setStringKeyLong',
            $context->context->functionType($void, false, $htPtr, $strPtr, $i64)
        );
        self::ensureExternal(
            $context,
            '__hashtable__setStringKeyString',
            $context->context->functionType($void, false, $htPtr, $strPtr, $strPtr)
        );
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        // getNamedFunction first — peer #31894 / #33550 (do not mint name.1).
        \PHPCompiler\JIT\LibcExtern::ensureExternalDecl($context, $name, $ft);
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
