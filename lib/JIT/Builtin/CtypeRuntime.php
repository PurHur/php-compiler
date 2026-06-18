<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __phpc_ctype_* via CtypeJitHelper + VmCtype PHP (#9234).
 *
 * php-src: ext/ctype/ctype.c
 */
final class CtypeRuntime
{
    private const HELPER_PATH = '/ext/ctype/CtypeJitHelper.php';

    private const CHECK_STRING = 'PHPCompiler\\ext\\ctype\\CtypeJitHelper::checkString';

    private const CHECK_INT = 'PHPCompiler\\ext\\ctype\\CtypeJitHelper::checkInt';

    public static function ensureLinked(Context $context): void
    {
        self::implementStringBridge($context);
        self::implementLongBridge($context);
        self::implementFromValueBridge($context);
    }

    private static function implementStringBridge(Context $context): void
    {
        $abiName = '__phpc_ctype_check_string';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        self::ensureJitHelperCompiled($context);

        $fn = null !== $probe
            ? $probe
            : self::declareStringFunction($context, $abiName);

        $entry = $fn->appendBasicBlock('ctype_string_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $i64 = $context->getTypeFromString('int64');
        $result = $context->builder->call(
            self::helperFunction($context, self::CHECK_STRING),
            $fn->getParam(0),
            $context->builder->sext($fn->getParam(1), $i64)
        );
        $i32 = $context->getTypeFromString('int32');
        $context->builder->returnValue($context->builder->zExt($result, $i32));
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementLongBridge(Context $context): void
    {
        self::implementParamBridge($context, '__phpc_ctype_check_long', self::CHECK_INT, 4);
    }

    private static function implementFromValueBridge(Context $context): void
    {
        $abiName = '__phpc_ctype_from_value';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        self::ensureJitHelperCompiled($context);

        $fn = null !== $probe
            ? $probe
            : self::declareFromValueFunction($context, $abiName);

        self::emitFromValueBridge($context, $fn);
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementParamBridge(
        Context $context,
        string $abiName,
        string $helperLogical,
        int $paramCount
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        self::ensureJitHelperCompiled($context);

        $fn = null !== $probe
            ? $probe
            : self::declareLongFunction($context, $abiName);

        $entry = $fn->appendBasicBlock('ctype_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $i64 = $context->getTypeFromString('int64');
        $args = [];
        for ($i = 0; $i < $paramCount; ++$i) {
            $param = $fn->getParam($i);
            $args[] = 0 === $i
                ? $param
                : $context->builder->sext($param, $i64);
        }
        $result = $context->builder->call(self::helperFunction($context, $helperLogical), ...$args);
        $i32 = $context->getTypeFromString('int32');
        $context->builder->returnValue($context->builder->zExt($result, $i32));
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function emitFromValueBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $valuePtr = $fn->getParam(0);
        $kind = $fn->getParam(1);
        $allowDigits = $fn->getParam(2);
        $allowMinus = $fn->getParam(3);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $zero32 = $i32->constInt(0, false);

        $stringBlock = BasicBlockHelper::append($context, 'ctype_value_string');
        $longBlock = BasicBlockHelper::append($context, 'ctype_value_long');
        $falseBlock = BasicBlockHelper::append($context, 'ctype_value_false');
        $doneBlock = BasicBlockHelper::append($context, 'ctype_value_done');
        $afterStringCheck = BasicBlockHelper::append($context, 'ctype_value_after_string');

        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_STRING & 0x7f, false)),
            $stringBlock,
            $afterStringCheck
        );

        $context->builder->positionAtEnd($stringBlock);
        $strPtr = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
        $stringResult = $context->builder->call(
            $context->lookupFunction('__phpc_ctype_check_string'),
            $strPtr,
            $kind
        );
        $stringEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterStringCheck);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_NATIVE_LONG, false)),
            $longBlock,
            $falseBlock
        );

        $context->builder->positionAtEnd($longBlock);
        $longVal = $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr);
        $longResult = $context->builder->call(
            $context->lookupFunction('__phpc_ctype_check_long'),
            $longVal,
            $kind,
            $allowDigits,
            $allowMinus
        );
        $longEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($falseBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($i32, 'ctype_value_result');
        $phi->addIncoming($stringResult, $stringEnd);
        $phi->addIncoming($longResult, $longEnd);
        $phi->addIncoming($zero32, $falseBlock);
        $context->builder->returnValue($phi);
    }

    private static function declareStringFunction(Context $context, string $name): LlvmFunction
    {
        return $context->module->addFunction(
            $name,
            $context->context->functionType(
                $context->getTypeFromString('int32'),
                false,
                $context->getTypeFromString('__string__*'),
                $context->getTypeFromString('int8')
            )
        );
    }

    private static function declareLongFunction(Context $context, string $name): LlvmFunction
    {
        return $context->module->addFunction(
            $name,
            $context->context->functionType(
                $context->getTypeFromString('int32'),
                false,
                $context->getTypeFromString('int64'),
                $context->getTypeFromString('int8'),
                $context->getTypeFromString('int8'),
                $context->getTypeFromString('int8')
            )
        );
    }

    private static function declareFromValueFunction(Context $context, string $name): LlvmFunction
    {
        return $context->module->addFunction(
            $name,
            $context->context->functionType(
                $context->getTypeFromString('int32'),
                false,
                $context->getTypeFromString('__value__*'),
                $context->getTypeFromString('int8'),
                $context->getTypeFromString('int8'),
                $context->getTypeFromString('int8')
            )
        );
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after CtypeJitHelper compile (#9234)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $needed = [\strtolower(self::CHECK_STRING), \strtolower(self::CHECK_INT)];
        $missing = false;
        foreach ($needed as $lc) {
            if (!isset($context->functions[$lc])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        $envKeys = [
            'PHP_COMPILER_SELFHOST_AOT',
            'PHP_COMPILER_EMIT_HELPER_LINK',
            'PHP_COMPILER_M3_EMIT_TU',
            'PHP_COMPILER_M3_COMPILE_DRIVER',
        ];
        $prevEnv = [];
        if (\function_exists('putenv')) {
            foreach ($envKeys as $key) {
                $prevEnv[$key] = \getenv($key);
                \putenv($key.'=0');
            }
        }
        try {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'CtypeJitHelper.php');
            if (null === $block) {
                throw new \LogicException('CtypeJitHelper.php parseAndCompile failed (#9234)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        } finally {
            if (\function_exists('putenv')) {
                foreach ($prevEnv as $key => $val) {
                    if (false === $val || null === $val) {
                        \putenv($key.'=');
                    } else {
                        \putenv($key.'='.$val);
                    }
                }
            }
        }
        foreach ($needed as $lc) {
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9234)');
            }
        }
    }
}
