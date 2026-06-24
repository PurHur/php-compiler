<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __phpc_ctype_* via CtypeJitHelper + VmCtype PHP (#9234, #9496).
 *
 * php-src: ext/ctype/ctype.c
 */
final class CtypeRuntime
{
    private const HELPER_PATH = '/ext/ctype/CtypeJitHelper.php';

    private const CHECK_STRING = 'PHPCompiler\\ext\\ctype\\CtypeJitHelper::checkString';

    private const CHECK_INT = 'PHPCompiler\\ext\\ctype\\CtypeJitHelper::checkInt';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::CHECK_STRING,
        self::CHECK_INT,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__phpc_ctype_check_string',
        '__phpc_ctype_check_long',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    private static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__phpc_ctype_check_string');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementStringBridge($context);
        self::implementLongBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'CtypeJitHelper.php');
            if (null === $block) {
                throw new \LogicException('CtypeJitHelper.php parseAndCompile failed (#9496)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9496)');
            }
        }
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after CtypeJitHelper compile (#9496)');
        }

        return $fn;
    }

    private static function implementStringBridge(Context $context): void
    {
        $abiName = '__phpc_ctype_check_string';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i32, false, $strPtr, $i8)
            );

        $entry = $fn->appendBasicBlock('ctype_string_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, self::CHECK_STRING),
            $fn->getParam(0),
            $context->builder->sext($fn->getParam(1), $i64)
        );
        $context->builder->returnValue($context->builder->zExt($result, $i32));
        $context->registerFunction($abiName, $fn);
    }

    private static function implementLongBridge(Context $context): void
    {
        $abiName = '__phpc_ctype_check_long';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i32, false, $i64, $i8, $i8, $i8)
            );

        $entry = $fn->appendBasicBlock('ctype_long_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, self::CHECK_INT),
            $fn->getParam(0),
            $context->builder->sext($fn->getParam(1), $i64),
            $context->builder->sext($fn->getParam(2), $i64),
            $context->builder->sext($fn->getParam(3), $i64)
        );
        $context->builder->returnValue($context->builder->zExt($result, $i32));
        $context->registerFunction($abiName, $fn);
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after CtypeRuntime bridge (#9496)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
