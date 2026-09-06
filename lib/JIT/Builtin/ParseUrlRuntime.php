<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __phpc_parse_url_* via ParseUrlJitHelper PHP (#9358, #22861, #27078, #33226, #33236).
 *
 * Assoc HT: {@see ParseUrlAssocLlvm}; component: {@see ParseUrlComponentLlvm} (leaf methods, not componentString).
 * Thin AOT call-site {@see ensureLinked} must {@see BasicBlockHelper::scopeLoweringToFunction}
 * so bridge blocks are not appended to user main (#27211 / Module.php:180).
 * Do not re-add always-on empty decls in {@see Type} — leftover decls mint parse_url_component.1
 * (#31894 / #32122 / #33236). SSOT: {@see \PHPCompiler\ext\standard\VmString}. php-src: ext/standard/url.c
 *
 * NestedJIT leaf helpers only (#36382): NestedJIT of componentString→pathOf SEGVs under thin AOT
 * for runtime URL strings; direct pathOf is fine. Force USER_SCRIPT_INLINE_ONLY for leaves.
 */
final class ParseUrlRuntime
{
    private const HELPER_PATH = '/ext/standard/ParseUrlJitHelper.php';

    private const SCHEME = 'PHPCompiler\\ext\\standard\\ParseUrlJitHelper::schemeOf';

    private const HOST = 'PHPCompiler\\ext\\standard\\ParseUrlJitHelper::hostOf';

    private const USER = 'PHPCompiler\\ext\\standard\\ParseUrlJitHelper::userOf';

    private const PASS = 'PHPCompiler\\ext\\standard\\ParseUrlJitHelper::passOf';

    private const PATH = 'PHPCompiler\\ext\\standard\\ParseUrlJitHelper::pathOf';

    private const QUERY = 'PHPCompiler\\ext\\standard\\ParseUrlJitHelper::queryOf';

    private const FRAGMENT = 'PHPCompiler\\ext\\standard\\ParseUrlJitHelper::fragmentOf';

    private const PORT = 'PHPCompiler\\ext\\standard\\ParseUrlJitHelper::portOf';

    private const HAS_USER = 'PHPCompiler\\ext\\standard\\ParseUrlJitHelper::hasUser';

    private const HAS_PASS = 'PHPCompiler\\ext\\standard\\ParseUrlJitHelper::hasPass';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::SCHEME,
        self::HOST,
        self::USER,
        self::PASS,
        self::PATH,
        self::QUERY,
        self::FRAGMENT,
        self::PORT,
        self::HAS_USER,
        self::HAS_PASS,
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
        self::implementIfMissing($context, '__phpc_parse_url_component', ParseUrlComponentLlvm::implement(...));
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
