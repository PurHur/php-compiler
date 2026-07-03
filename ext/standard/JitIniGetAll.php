<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringCaseCompare;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for ini_get_all() — compile-time VmIni tables + optional details select (#3205).
 *
 * php-src: ext/standard/ini.c — PHP_FUNCTION(ini_get_all)
 */
final class JitIniGetAll
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 2) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'ini_get_all() expects at most 2 arguments, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        if ($argc >= 1 && !self::isNullExtensionArg($args[0])) {
            return self::invokeWithRuntimeExtension($context, $args);
        }

        return self::invokeMaterialized($context, null, $args);
    }

  /**
     * @param list<JITVariable> $args
     */
    private static function invokeWithRuntimeExtension(Context $context, array $args): Value
    {
        $argc = \count($args);
        $literal = $args[0]->compileTimeString ?? JitStringArg::compileTimeLiteral($args[0]);
        if (null !== $literal) {
            if ('core' !== strtolower($literal)) {
                $slot = JitValueBox::alloc($context);
                $ptr = JitValueBox::pointer($context, $slot);
                JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

                return $ptr;
            }

            return self::invokeMaterialized($context, $literal, $args);
        }

        $detailHt = self::materializeCoreTables($context, true);
        $flatHt = self::materializeCoreTables($context, false);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $extStr = JitStringBuiltinArg::lower($context, $args[0], 'ini_get_all', 0, 'extension');

        $okBlock = BasicBlockHelper::append($context, 'iga_ext_runtime_ok');
        $failBlock = BasicBlockHelper::append($context, 'iga_ext_runtime_fail');
        $doneBlock = BasicBlockHelper::append($context, 'iga_ext_runtime_done');
        $isCore = self::emitStringEqualsCi($context, $extStr, 'core');
        $context->builder->branchIf($isCore, $okBlock, $failBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        self::writeResultHashtable($context, $ptr, $detailHt, $flatHt, $argc >= 2 ? $args[1] : null);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function invokeMaterialized(Context $context, ?string $extension, array $args): Value
    {
        $argc = \count($args);
        $vmCtx = $context->runtime->vmContext;
        if (null === $vmCtx) {
            throw new \LogicException('ini_get_all() requires VM context during JIT lowering (issue #3205)');
        }

        $detailTable = VmIni::getAll($vmCtx, $extension, true);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        if (false === $detailTable) {
            JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

            return $ptr;
        }

        $flatTable = VmIni::getAll($vmCtx, $extension, false);
        if (false === $flatTable) {
            JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

            return $ptr;
        }

        $detailHt = $context->helper->loadValue(
            HashTableHelper::variableFromVmHashTable($context, $detailTable)
        );
        $flatHt = $context->helper->loadValue(
            HashTableHelper::variableFromVmHashTable($context, $flatTable)
        );
        self::writeResultHashtable($context, $ptr, $detailHt, $flatHt, $argc >= 2 ? $args[1] : null);

        return $ptr;
    }

    private static function materializeCoreTables(Context $context, bool $details): Value
    {
        $vmCtx = $context->runtime->vmContext;
        if (null === $vmCtx) {
            throw new \LogicException('ini_get_all() requires VM context during JIT lowering (issue #3205)');
        }
        $table = VmIni::getAll($vmCtx, null, $details);
        if (false === $table) {
            throw new \LogicException('ini_get_all() core table materialization failed (issue #3205)');
        }

        return $context->helper->loadValue(
            HashTableHelper::variableFromVmHashTable($context, $table)
        );
    }

    private static function writeResultHashtable(
        Context $context,
        Value $ptr,
        Value $detailHt,
        Value $flatHt,
        ?JITVariable $detailsArg
    ): void {
        if (null === $detailsArg) {
            $context->builder->call(
                $context->lookupFunction('__value__writeHashtable'),
                $ptr,
                $detailHt
            );

            return;
        }

        if (JITVariable::TYPE_NATIVE_BOOL !== $detailsArg->type) {
            throw new \LogicException(
                'ini_get_all() details flag must be a boolean in this compiler build (issue #3205)'
            );
        }

        $wantDetails = $context->helper->loadValue($detailsArg);
        $chosen = $context->builder->select($wantDetails, $detailHt, $flatHt);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $chosen
        );
    }

    private static function emitStringEqualsCi(Context $context, Value $str, string $literal): Value
    {
        StringCaseCompare::ensureStrcasecmpLinked($context);
        $strMap = $context->structFieldMap['__string__'];
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $bytes = $context->builder->structGep($str, $strMap['value']);
        $dataPtr = $context->builder->pointerCast($bytes, $i8p);
        $litPtr = $context->builder->pointerCast($context->constantFromString($literal), $i8p);
        $cmp = $context->builder->call($context->lookupFunction('strcasecmp'), $dataPtr, $litPtr);

        return $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
    }

    private static function isNullExtensionArg(JITVariable $arg): bool
    {
        return JITVariable::TYPE_VALUE === $arg->type && ($arg->isNullConstant ?? false);
    }
}
