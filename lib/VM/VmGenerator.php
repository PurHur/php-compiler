<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * JIT/AOT generator state LLVM helpers — PHP SSOT (#10105, php-in-PHP).
 *
 * Runtime semantics: {@see GeneratorState} · php-src: Zend/zend_generators.c
 */
final class VmGenerator
{
    public const TARGET_PROPERTY = GeneratorJitHelper::TARGET_PROPERTY;

    public const STATE_PROPERTY = GeneratorJitHelper::STATE_PROPERTY;

    private static bool $typesRegistered = false;

    public static function ensureJitTypes(Context $context): void
    {
        // Per-context map — static flag alone skipped setBody/map on a second LLVM context (#35142).
        if (isset($context->structFieldMap['__generator_state__']['frame_slots'])) {
            return;
        }
        $struct = $context->context->namedStructType('__generator_state__');
        $context->registerType('__generator_state__', $struct);
        $context->registerType('__generator_state__*', $struct->pointerType(0));
        if (!self::$typesRegistered) {
            self::$typesRegistered = true;
            $struct->setBody(
                false,
                $context->getTypeFromString('size_t'),
                $context->getTypeFromString('size_t'),
                $context->getTypeFromString('int1'),
                $context->getTypeFromString('int1'),
                $context->getTypeFromString('__value__'),
                $context->getTypeFromString('__value__'),
                $context->getTypeFromString('int1'),
                $context->getTypeFromString('__hashtable__*'),
                $context->getTypeFromString('size_t'),
                $context->getTypeFromString('int1'),
                $context->getTypeFromString('int1'),
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('int1'),
                $context->getTypeFromString('__value__'),
                $context->getTypeFromString('int1'),
                $context->getTypeFromString('int1'),
                $context->getTypeFromString('__value__'),
                $context->getTypeFromString('__value__'),
                $context->getTypeFromString('int1'),
                $context->getTypeFromString('int1'),
                $context->getTypeFromString('int1'),
                $context->getTypeFromString('__value__*'),
            );
        }
        $context->structFieldMap['__generator_state__'] = [
            'resume_ip' => 0,
            'auto_key' => 1,
            'has_current' => 2,
            'done' => 3,
            'current_key' => 4,
            'current_value' => 5,
            'yield_from_active' => 6,
            'yield_from_ht' => 7,
            'yield_from_idx' => 8,
            'yield_from_is_generator' => 9,
            'yield_from_is_iterator' => 10,
            'yield_from_iter_obj' => 11,
            'yield_from_iter_advance' => 12,
            'pending_send' => 13,
            'has_pending_send' => 14,
            'has_pending_throw' => 15,
            'pending_throw' => 16,
            'return_value' => 17,
            'has_returned' => 18,
            'at_first_yield' => 19,
            'foreach_needs_advance' => 20,
            'frame_slots' => 21,
        ];
    }

    public static function clearPendingAndReturnFields(Context $context, Value $statePtr): void
    {
        $map = $context->structFieldMap['__generator_state__'];
        $i1 = $context->getTypeFromString('int1');
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($statePtr, $map['has_pending_send']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($statePtr, $map['has_pending_throw']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($statePtr, $map['has_returned']));
        foreach (['pending_send', 'pending_throw', 'return_value'] as $field) {
            $context->builder->call(
                $context->lookupFunction('__value__writeNull'),
                JitValueBox::pointer($context, $context->builder->structGep($statePtr, $map[$field]))
            );
        }
    }

    public static function clearYieldFromFields(Context $context, Value $statePtr): void
    {
        $map = $context->structFieldMap['__generator_state__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
        $htPtrTy = $context->getTypeFromString('__hashtable__*');
        $objPtrTy = $context->getTypeFromString('__object__*');
        $zero = $sizeT->constInt(0, false);
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($statePtr, $map['yield_from_active']));
        $context->builder->store($htPtrTy->constNull(), $context->builder->structGep($statePtr, $map['yield_from_ht']));
        $context->builder->store($zero, $context->builder->structGep($statePtr, $map['yield_from_idx']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($statePtr, $map['yield_from_is_generator']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($statePtr, $map['yield_from_is_iterator']));
        $context->builder->store($objPtrTy->constNull(), $context->builder->structGep($statePtr, $map['yield_from_iter_obj']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($statePtr, $map['yield_from_iter_advance']));
    }

    public static function resetStateInPlace(Context $context, Value $statePtr): void
    {
        $map = $context->structFieldMap['__generator_state__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
        $zero = $sizeT->constInt(0, false);
        $context->builder->store($zero, $context->builder->structGep($statePtr, $map['resume_ip']));
        $context->builder->store($zero, $context->builder->structGep($statePtr, $map['auto_key']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($statePtr, $map['has_current']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($statePtr, $map['done']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($statePtr, $map['at_first_yield']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($statePtr, $map['foreach_needs_advance']));
        self::clearYieldFromFields($context, $statePtr);
        $framePtrTy = $context->getTypeFromString('__value__*');
        $context->builder->store($framePtrTy->constNull(), $context->builder->structGep($statePtr, $map['frame_slots']));
    }

    public static function copyCurrentFromInnerToOuter(
        Context $context,
        Value $outerState,
        Value $innerState
    ): void {
        $map = $context->structFieldMap['__generator_state__'];
        $outerKey = $context->builder->structGep($outerState, $map['current_key']);
        $innerKey = $context->builder->structGep($innerState, $map['current_key']);
        JitValueBox::copyFromPointer($context, $outerKey, $innerKey);
        $outerVal = $context->builder->structGep($outerState, $map['current_value']);
        $innerVal = $context->builder->structGep($innerState, $map['current_value']);
        JitValueBox::copyFromPointer($context, $outerVal, $innerVal);
    }

    public static function emitCreateFromCall(
        \PHPCompiler\JIT $jit,
        string $resumeInternalName,
        array $callArgs = []
    ): Variable {
        return self::emitCreateFromCallContext($jit->context, $resumeInternalName, $callArgs);
    }

    /** Context-only entry for foreach Aggregate→Generator unwrap (#34980). */
    public static function emitCreateFromCallContext(
        Context $context,
        string $resumeInternalName,
        array $callArgs = []
    ): Variable {
        self::ensureJitTypes($context);
        $stateTy = $context->getTypeFromString('__generator_state__');
        $statePtr = $context->memory->malloc($stateTy);
        $map = $context->structFieldMap['__generator_state__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
        $zero = $sizeT->constInt(0, false);
        $context->builder->store($zero, $context->builder->structGep($statePtr, $map['resume_ip']));
        $context->builder->store($zero, $context->builder->structGep($statePtr, $map['auto_key']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($statePtr, $map['has_current']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($statePtr, $map['done']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($statePtr, $map['at_first_yield']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($statePtr, $map['foreach_needs_advance']));
        self::clearYieldFromFields($context, $statePtr);
        self::clearPendingAndReturnFields($context, $statePtr);
        $framePtrTy = $context->getTypeFromString('__value__*');
        $context->builder->store($framePtrTy->constNull(), $context->builder->structGep($statePtr, $map['frame_slots']));
        self::initFrameWithCallArgs($context, $statePtr, $resumeInternalName, $callArgs);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $context->builder->structGep($statePtr, $map['current_key']))
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $context->builder->structGep($statePtr, $map['current_value']))
        );

        $classId = $context->type->object->lookup('Generator');
        $obj = $context->type->object->allocate($classId);
        $context->type->object->markObjectConstructed($obj);
        self::storeResumeName($context, $obj, $resumeInternalName);
        $stateBits = $context->builder->ptrtoint(
            $statePtr,
            $context->getTypeFromString('int64')
        );
        $stateBitsVar = new Variable($context, Variable::TYPE_NATIVE_LONG, Variable::KIND_VALUE, $stateBits);
        $context->type->object->storeInstanceProperty($obj, 'Generator', self::STATE_PROPERTY, $stateBitsVar);

        $var = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $obj);
        $var->generatorStatePtr = $statePtr;
        $var->generatorResumeName = $resumeInternalName;
        $var->isJitGenerator = true;

        return $var;
    }

    /**
     * Allocate frame_slots and copy call argv into formal slots (#35142).
     *
     * @param list<Variable> $callArgs
     */
    private static function initFrameWithCallArgs(
        Context $context,
        Value $statePtr,
        string $resumeInternalName,
        array $callArgs
    ): void {
        $resumeLc = strtolower($resumeInternalName);
        $frameIndex = $context->generatorCreatorFrameIndex[$resumeLc] ?? [];
        if ([] === $frameIndex) {
            // Resume symbol may be llvm-sanitized; try raw creator map values.
            foreach ($context->generatorCreatorFrameIndex as $key => $idxMap) {
                if (str_ends_with($key, '__resume') || $key === $resumeLc) {
                    $frameIndex = $idxMap;
                    break;
                }
            }
        }
        $count = \count($frameIndex);
        // Ensure capacity for positional argv even if name indexing missed formals.
        $count = max($count, \count($callArgs));
        $map = $context->structFieldMap['__generator_state__'];
        $frameGep = $context->builder->structGep($statePtr, $map['frame_slots']);
        $valueTy = $context->getTypeFromString('__value__');
        $valuePtrTy = $context->getTypeFromString('__value__*');
        if ($count <= 0) {
            $context->builder->store($valuePtrTy->constNull(), $frameGep);

            return;
        }
        $oneSize = $context->builder->ptrToInt(
            $context->builder->gep(
                $valueTy->pointerType(0)->constNull(),
                $context->context->int32Type()->constInt(1, false)
            ),
            $context->getTypeFromString('size_t')
        );
        $bytes = $context->builder->mul(
            $oneSize,
            $context->getTypeFromString('size_t')->constInt($count, false)
        );
        $raw = $context->builder->call($context->lookupFunction('__mm__malloc'), $bytes);
        $slots = $context->builder->pointerCast($raw, $valuePtrTy);
        for ($i = 0; $i < $count; ++$i) {
            $slot = $context->builder->gep(
                $slots,
                $context->context->int32Type()->constInt($i, false)
            );
            $context->builder->call(
                $context->lookupFunction('__value__writeNull'),
                JitValueBox::pointer($context, $slot)
            );
        }
        $context->builder->store($slots, $frameGep);

        // Map call args by formal order registered at resume compile.
        $logical = $resumeLc;
        if (str_ends_with($logical, '__resume')) {
            $logical = substr($logical, 0, -strlen('__resume'));
        }
        $paramNames = $context->generatorCreatorParamNames[$logical] ?? [];
        if ([] === $paramNames && [] !== $callArgs) {
            // Positional fallback when ARG_RECV names were not recorded.
            $byIndex = array_values($frameIndex);
            sort($byIndex);
            foreach ($callArgs as $paramIdx => $arg) {
                if (!$arg instanceof Variable || !isset($byIndex[$paramIdx])) {
                    continue;
                }
                $slot = $context->builder->gep(
                    $slots,
                    $context->context->int32Type()->constInt($byIndex[$paramIdx], false)
                );
                \PHPCompiler\JIT\GeneratorHelper::assignValueField($context, $slot, $arg, null);
            }

            return;
        }
        foreach ($paramNames as $paramIdx => $paramName) {
            if (!isset($callArgs[$paramIdx]) || !isset($frameIndex[$paramName])) {
                continue;
            }
            $arg = $callArgs[$paramIdx];
            if (!$arg instanceof Variable) {
                continue;
            }
            $slot = $context->builder->gep(
                $slots,
                $context->context->int32Type()->constInt($frameIndex[$paramName], false)
            );
            \PHPCompiler\JIT\GeneratorHelper::assignValueField($context, $slot, $arg, null);
        }
    }

    private static function storeResumeName(Context $context, Value $obj, string $resumeName): void
    {
        $targetStr = new Variable(
            $context,
            Variable::TYPE_STRING,
            Variable::KIND_VALUE,
            $context->builder->load($context->constantStringFromString(strtolower($resumeName)))
        );
        $targetStr->addref();
        $context->type->object->storeInstanceProperty(
            $obj,
            'Generator',
            self::TARGET_PROPERTY,
            $targetStr
        );
    }
}
