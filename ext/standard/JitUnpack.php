<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\UnpackJitRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helper for unpack() via __compiler_unpack (issue #3188, #5442, #27662).
 *
 * Soft-null $format on PROFILE=8.4 — Zend DEP+[] (#21478, reverts #20241 TypeError).
 * $string soft-null on 8.4 (#21246, pack.c) — DEP+coerce, not TypeError.
 *
 * Thin AOT: single-code integer formats (n/N/v/V/…) lower via HashTableHelper — no
 * UnpackEngine NestedJIT (OOM, #27662). Full NestedJIT remains for embed JIT / complex formats.
 */
final class JitUnpack
{
    public static function unpack(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'unpack() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        if ($argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'unpack() expects at most 3 arguments, %d given',
                $argc
            ));
        }

        $fmtLit = $args[0]->compileTimeString ?? null;
        $dataLit = $args[1]->compileTimeString ?? null;
        $offsetLit = 0;
        if (3 === $argc) {
            $offsetLit = $args[2]->compileTimeLong ?? null;
        }
        if (null !== $fmtLit && null !== $dataLit && null !== $offsetLit) {
            $ht = VmPack::unpackToHashTable($fmtLit, $dataLit, (int) $offsetLit);
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            if (null === $ht) {
                $context->builder->call(
                    $context->lookupFunction('__value__writeBool'),
                    $ptr,
                    $context->getTypeFromString('int32')->constInt(0, false)
                );
            } else {
                $htVar = JitSscanf::materializeVmHashTable($context, $ht);
                $context->builder->call(
                    $context->lookupFunction('__value__writeHashtable'),
                    $ptr,
                    HashTableHelper::loadHashtablePointer($context, $htVar)
                );
            }

            return $ptr;
        }

        $thinSimple = $context->isThinStandaloneAotMain()
            && null !== $fmtLit
            && null !== ($spec = self::parseThinIntSpec($fmtLit));

        if (!$thinSimple) {
            UnpackJitRuntime::ensureLinked($context);
        }

        // Soft-null $format on 8.4 — Zend deprecate+coerce (#21478, reverts #20241 TypeError).
        $nullFormat = JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false);
        $fmt = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'unpack', 0, 'format')
            : JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'unpack', 0, 'format');
        if ($nullFormat && $context->callerStrictTypes) {
            return $context->constantFromBool(false);
        }
        $data = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[1], 'unpack', 1, 'string')
            : JitStringBuiltinArg::lowerTrimFamilyString($context, $args[1], 'unpack', 1, 'string');
        if ($context->callerStrictTypes && (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false))) {
            return $context->constantFromBool(false);
        }
        $offset = $context->getTypeFromString('int64')->constInt(0, false);
        if (3 === $argc) {
            $offset = JitIntdiv::lowerIntBuiltinArgForCaller($context, $args[2], 'unpack', 3, 'offset');
        }

        if ($thinSimple) {
            return self::emitThinIntUnpack($context, $spec, $data, $offset);
        }

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__compiler_unpack'),
            $fmt,
            $data,
            $offset,
            $ptr
        );

        return $ptr;
    }

    /**
     * @return array{code: string, name: string, size: int, be: bool, unsigned: bool}|null
     */
    private static function parseThinIntSpec(string $format): ?array
    {
        if ('' === $format) {
            return null;
        }
        $code = $format[0];
        $meta = self::thinIntMeta($code);
        if (null === $meta) {
            return null;
        }
        $name = \substr($format, 1);
        if (\strpbrk($name, '*/@') !== false) {
            return null;
        }

        return [
            'code' => $code,
            'name' => $name,
            'size' => $meta['size'],
            'be' => $meta['be'],
            'unsigned' => $meta['unsigned'],
        ];
    }

    /** @return array{size: int, be: bool, unsigned: bool}|null */
    private static function thinIntMeta(string $code): ?array
    {
        return match ($code) {
            'n' => ['size' => 2, 'be' => true, 'unsigned' => true],
            'N' => ['size' => 4, 'be' => true, 'unsigned' => true],
            'v' => ['size' => 2, 'be' => false, 'unsigned' => true],
            'V' => ['size' => 4, 'be' => false, 'unsigned' => true],
            'J' => ['size' => 8, 'be' => true, 'unsigned' => true],
            'P' => ['size' => 8, 'be' => false, 'unsigned' => true],
            'C' => ['size' => 1, 'be' => true, 'unsigned' => true],
            'c' => ['size' => 1, 'be' => true, 'unsigned' => false],
            default => null,
        };
    }

    /**
     * @param array{code: string, name: string, size: int, be: bool, unsigned: bool} $spec
     */
    private static function emitThinIntUnpack(
        Context $context,
        array $spec,
        Value $dataStr,
        Value $offset
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $stringMap = $context->structFieldMap['__string__'];

        $id = (string) \spl_object_id($context);
        $failBb = BasicBlockHelper::append($context, 'unpack_thin_fail_'.$id);
        $okBb = BasicBlockHelper::append($context, 'unpack_thin_ok_'.$id);
        $doneBb = BasicBlockHelper::append($context, 'unpack_thin_done_'.$id);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $len = $context->builder->load($context->builder->structGep($dataStr, $stringMap['length']));
        $need = $context->builder->add($offset, $i64->constInt($spec['size'], false));
        $tooShort = $context->builder->icmp(Builder::INT_UGT, $need, $len);
        $context->builder->branchIf($tooShort, $failBb, $okBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $ptr,
            $context->getTypeFromString('int32')->constInt(0, false)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okBb);
        $base = $context->builder->pointerCast(
            $context->builder->structGep($dataStr, $stringMap['value']),
            $i8p
        );
        $at = $context->builder->gep($base, $context->builder->truncOrBitCast($offset, $sizeT));
        $value = $i64->constInt(0, false);
        for ($i = 0; $i < $spec['size']; ++$i) {
            $byteIndex = $spec['be'] ? $i : ($spec['size'] - 1 - $i);
            $bptr = $context->builder->gep($at, $sizeT->constInt($byteIndex, false));
            $b = $context->builder->zExt(
                $context->builder->load($context->builder->pointerCast($bptr, $i8->pointerType(0))),
                $i64
            );
            $value = $context->builder->or(
                $context->builder->shl($value, $i64->constInt(8, false)),
                $b
            );
        }
        if (!$spec['unsigned'] && $spec['size'] < 8) {
            // Sign-extend via shift (size 1 or 2 typically for c/s).
            $bits = $spec['size'] * 8;
            $shift = 64 - $bits;
            $value = $context->builder->aShr(
                $context->builder->shl($value, $i64->constInt($shift, false)),
                $i64->constInt($shift, false)
            );
        }

        $ht = HashTableHelper::alloc($context);
        $htVar = new JITVariable($context, JITVariable::TYPE_HASHTABLE, JITVariable::KIND_VALUE, $ht);
        $valVar = new JITVariable($context, JITVariable::TYPE_NATIVE_LONG, JITVariable::KIND_VALUE, $value);
        if ('' === $spec['name']) {
            $keyVar = new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_LONG,
                JITVariable::KIND_VALUE,
                $i64->constInt(1, false)
            );
            $keyVar->compileTimeLong = 1;
            HashTableHelper::addElement($context, $htVar, $valVar, $keyVar);
        } else {
            $keyStr = $context->builder->load($context->constantStringFromString($spec['name']));
            $keyVar = new JITVariable(
                $context,
                JITVariable::TYPE_STRING,
                JITVariable::KIND_VALUE,
                $keyStr
            );
            $keyVar->compileTimeString = $spec['name'];
            HashTableHelper::addElement($context, $htVar, $valVar, $keyVar);
        }
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            HashTableHelper::loadHashtablePointer($context, $htVar)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $ptr;
    }
}
