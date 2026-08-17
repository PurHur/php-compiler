<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringCaseCompare;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TryCatchHelper;
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
        if (self::isInvalidExtensionScalar($context, $args[0])) {
            return self::emitExtensionScalarTypeError($context, $args[0]);
        }

        if (!$context->callerStrictTypes && self::isNativeScalarExtensionArg($args[0])) {
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

            return $ptr;
        }

        $literal = JITVariable::TYPE_STRING === $args[0]->type
            ? ($args[0]->compileTimeString ?? JitStringArg::compileTimeLiteral($args[0]))
            : null;
        if (null !== $literal) {
            if (!VmIni::isKnownIniExtension($literal)) {
                $slot = JitValueBox::alloc($context);
                $ptr = JitValueBox::pointer($context, $slot);
                JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

                return $ptr;
            }

            return self::invokeMaterialized($context, strtolower($literal), $args);
        }

        return self::invokeRuntimeExtensionSelect($context, $args);
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function invokeRuntimeExtensionSelect(Context $context, array $args): Value
    {
        $argc = \count($args);
        $extStr = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerRequiredString(
                $context,
                $args[0],
                'ini_get_all',
                0,
                'extension',
                '?string'
            )
            : JitStringBuiltinArg::lower(
                $context,
                $args[0],
                'ini_get_all',
                0,
                'extension',
                '?string'
            );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $branches = [
            ['core', null],
            ['standard', 'standard'],
            ['pcre', 'pcre'],
        ];

        $failBlock = BasicBlockHelper::append($context, 'iga_ext_runtime_fail');
        $doneBlock = BasicBlockHelper::append($context, 'iga_ext_runtime_done');
        $nextBlock = BasicBlockHelper::append($context, 'iga_ext_runtime_try0');

        $context->builder->branch($nextBlock);

        foreach ($branches as $index => [$label, $extension]) {
            $tryBlock = $nextBlock;
            $matchBlock = BasicBlockHelper::append($context, 'iga_ext_runtime_match'.$index);
            $nextBlock = BasicBlockHelper::append($context, 'iga_ext_runtime_try'.($index + 1));

            $context->builder->positionAtEnd($tryBlock);
            $isMatch = self::emitStringEqualsCi($context, $extStr, $label);
            $context->builder->branchIf($isMatch, $matchBlock, $nextBlock);

            $context->builder->positionAtEnd($matchBlock);
            $detailHt = self::materializeExtensionTables($context, $extension, true);
            $flatHt = self::materializeExtensionTables($context, $extension, false);
            self::writeResultHashtable($context, $ptr, $detailHt, $flatHt, $argc >= 2 ? $args[1] : null);
            $context->builder->branch($doneBlock);
        }

        $context->builder->positionAtEnd($nextBlock);
        $context->builder->branch($failBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
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

    private static function materializeExtensionTables(Context $context, ?string $extension, bool $details): Value
    {
        $vmCtx = $context->runtime->vmContext;
        if (null === $vmCtx) {
            throw new \LogicException('ini_get_all() requires VM context during JIT lowering (issue #3205)');
        }
        $table = VmIni::getAll($vmCtx, $extension, $details);
        if (false === $table) {
            throw new \LogicException('ini_get_all() extension table materialization failed (issue #9052)');
        }

        return $context->helper->loadValue(
            HashTableHelper::variableFromVmHashTable($context, $table)
        );
    }

    private static function materializeCoreTables(Context $context, bool $details): Value
    {
        return self::materializeExtensionTables($context, null, $details);
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
        $cmp = $context->builder->call($context->lookupFunction(StringCaseCompare::ABI_STRCASECMP), $dataPtr, $litPtr);

        return $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
    }

    private static function isNullExtensionArg(JITVariable $arg): bool
    {
        if (NamedOptionalCallArgs::isOmittedOptional($arg)) {
            return true;
        }

        return JITVariable::TYPE_NULL === $arg->type
            || (JITVariable::TYPE_VALUE === $arg->type && ($arg->isNullConstant ?? false));
    }

    private static function isInvalidExtensionScalar(Context $context, JITVariable $arg): bool
    {
        if (!$context->callerStrictTypes) {
            return false;
        }

        return self::isNativeScalarExtensionArg($arg);
    }

    private static function isNativeScalarExtensionArg(JITVariable $arg): bool
    {
        return JITVariable::TYPE_NATIVE_BOOL === $arg->type
            || JITVariable::TYPE_NATIVE_LONG === $arg->type
            || JITVariable::TYPE_NATIVE_DOUBLE === $arg->type;
    }

    private static function emitExtensionScalarTypeError(Context $context, JITVariable $arg): Value
    {
        $given = match ($arg->type) {
            JITVariable::TYPE_NATIVE_BOOL => 'bool',
            JITVariable::TYPE_NATIVE_LONG => 'int',
            JITVariable::TYPE_NATIVE_DOUBLE => 'float',
            default => 'mixed',
        };
        TypeErrorRaise::ensureLinked($context);
        $message = \sprintf(
            'ini_get_all(): Argument #1 ($extension) must be of type ?string, %s given',
            $given
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        if (null !== TryCatchHelper::resolveThrowHandler($context)) {
            TryCatchHelper::emitCatchableClassError($context, 'TypeError', $message);

            return $ptr;
        }
        TypeErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('abort'));

        return $ptr;
    }
}
