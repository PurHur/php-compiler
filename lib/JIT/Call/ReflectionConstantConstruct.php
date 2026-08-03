<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\standard\JitConstant;
use PHPCompiler\ext\standard\VmConstants;
use PHPCompiler\JIT\Builtin\ReflectionNative;
use PHPCompiler\JIT\Builtin\ReflectionRuntime;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\ReflectionSupport;
use PHPLLVM\Value;

/**
 * ReflectionConstant::__construct($name) or ($class, $name) — JIT/AOT (#4136, #17341, #27303).
 *
 * Single-arg globals: store the constant name in `$constant` (and `$name` for Zend's public
 * surface). Resolve the value at construct time into `$value` so getValue() works under AOT
 * without a runtime core-constant table seed.
 */
final class ReflectionConstantConstruct implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        ReflectionRuntime::ensureLinked($context);
        ReflectionNative::registerDeclarations($context);
        if (\count($args) < 2) {
            throw new \ArgumentCountError(
                'ReflectionConstant::__construct() expects at least 1 argument, 0 given'
            );
        }
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        // Default cached value = null (TYPE_NULL box).
        self::emitWriteNullValueProp($context, $obj);

        if (\count($args) < 3) {
            $lit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
            if (null !== $lit) {
                $cstr = $context->builder->pointerCast($context->constantFromString($lit), $i8p);
                $len = $sizeT->constInt(\strlen($lit), false);
                // Match VM: PROP_CLASS_NAME empty for globals; PROP_CONSTANT_NAME holds the name.
                ReflectionSetup::emitSetStringPropertyFromCstr(
                    $context,
                    $obj,
                    'ReflectionConstant',
                    ReflectionSupport::PROP_CLASS_NAME,
                    $context->builder->pointerCast($context->constantFromString(''), $i8p),
                    $sizeT->constInt(0, false)
                );
                ReflectionSetup::emitSetStringPropertyFromCstr(
                    $context,
                    $obj,
                    'ReflectionConstant',
                    ReflectionSupport::PROP_CONSTANT_NAME,
                    $cstr,
                    $len
                );
                self::emitCacheResolvedValue($context, $obj, $lit);
            } else {
                ReflectionSetup::emitSetStringPropertyFromCstr(
                    $context,
                    $obj,
                    'ReflectionConstant',
                    ReflectionSupport::PROP_CLASS_NAME,
                    $context->builder->pointerCast($context->constantFromString(''), $i8p),
                    $sizeT->constInt(0, false)
                );
                ReflectionSetup::emitSetStringPropertyFromVar(
                    $context,
                    $obj,
                    'ReflectionConstant',
                    ReflectionSupport::PROP_CONSTANT_NAME,
                    $args[1]
                );
            }
            ReflectionSetup::markConstructed($context, $obj);
        } else {
            ReflectionSetup::emitSetStringPropertyFromVar(
                $context,
                $obj,
                'ReflectionConstant',
                ReflectionSupport::PROP_CLASS_NAME,
                $args[1]
            );
            ReflectionSetup::emitSetStringPropertyFromVar(
                $context,
                $obj,
                'ReflectionConstant',
                ReflectionSupport::PROP_CONSTANT_NAME,
                $args[2]
            );
            ReflectionSetup::markConstructed($context, $obj);
        }

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }

    private static function emitWriteNullValueProp(Context $context, Value $obj): void
    {
        $slot = $context->type->object->propertySlotFor($obj, 'ReflectionConstant', 'value');
        $valueType = $context->getTypeFromString('__value__');
        $heapVal = $context->memory->malloc($valueType);
        $heapPtr = $context->builder->pointerCast(
            $heapVal,
            $context->getTypeFromString('__value__*')
        );
        $context->builder->call($context->lookupFunction('__value__writeNull'), $heapPtr);
        $voidPtr = $context->getTypeFromString('void*');
        $context->builder->store(
            $context->builder->pointerCast($heapPtr, $voidPtr),
            $slot
        );
    }

    private static function emitCacheResolvedValue(Context $context, Value $obj, string $lit): void
    {
        $vmContext = $context->runtime->vmContext;
        if (null === $vmContext) {
            return;
        }
        $phpVar = VmConstants::globalConstantLookup($vmContext, $lit);
        if (null === $phpVar) {
            return;
        }
        // Reuse constant() literal lowering to materialize a __value__* box, then store it.
        $nameJit = new Variable(
            $context,
            Variable::TYPE_STRING,
            Variable::KIND_VALUE,
            $context->builder->load($context->constantStringFromString($lit))
        );
        $nameJit->compileTimeString = $lit;
        $valuePtr = JitConstant::invoke($context, $nameJit);
        $slot = $context->type->object->propertySlotFor($obj, 'ReflectionConstant', 'value');
        $voidPtr = $context->getTypeFromString('void*');
        $context->builder->store(
            $context->builder->pointerCast($valuePtr, $voidPtr),
            $slot
        );
    }
}
