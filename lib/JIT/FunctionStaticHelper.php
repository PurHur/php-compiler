<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Value;

/**
 * Lazy init for function-local static variables (#2286, #10173).
 *
 * Init flags route through {@see Builtin\FunctionStaticRuntime} module table ABI;
 * value materialization stays in LLVM ({@see writeDefault}).
 */
final class FunctionStaticHelper
{
    /** @var array<string, int> */
    private static array $slotIndexByKey = [];

    private static int $nextSlotIndex = 0;

    /**
     * Init-table ABI bodies live in FunctionStaticRuntime, which only the full
     * standalone-bodies path emits; user-script AOT uses the minimal list and
     * crashed on lookup ('non-existing function phpc_fn_static_is_initialized').
     * Ensure idempotently here, preserving the caller's insertion point
     * (implement() clears it) — same pattern as JitVmHelperLink::ensureBridge.
     */
    private static function ensureRuntime(Context $context): void
    {
        $saved = null;
        try {
            $saved = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }
        Builtin\FunctionStaticRuntime::ensureLinked($context);
        if (null !== $saved) {
            $context->builder->positionAtEnd($saved);
        }
    }

    public static function emitLazyInit(Context $context, string $key, Variable $storage, Variable $default): void
    {
        self::ensureRuntime($context);
        $slotId = self::slotConst($context, $key);
        $isInit = $context->builder->call(
            $context->lookupFunction('phpc_fn_static_is_initialized'),
            $slotId
        );
        $initBlock = BasicBlockHelper::append($context, 'fn_static_init');
        $doneBlock = BasicBlockHelper::append($context, 'fn_static_done');
        $context->builder->branchIf($isInit, $doneBlock, $initBlock);
        $context->builder->positionAtEnd($initBlock);
        self::writeDefault($context, $storage, $default);
        $context->builder->call(
            $context->lookupFunction('phpc_fn_static_mark_initialized'),
            $slotId
        );
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($doneBlock);
    }

    public static function isInitializedCondition(Context $context, string $key): Value
    {
        self::ensureRuntime($context);

        return $context->builder->call(
            $context->lookupFunction('phpc_fn_static_is_initialized'),
            self::slotConst($context, $key)
        );
    }

    public static function emitRuntimeInitStore(Context $context, string $key, Variable $storage, Variable $value): void
    {
        self::ensureRuntime($context);
        self::writeDefault($context, $storage, $value);
        $context->builder->call(
            $context->lookupFunction('phpc_fn_static_mark_initialized'),
            self::slotConst($context, $key)
        );
    }

    public static function writeDefault(Context $context, Variable $storage, Variable $default): void
    {
        $destPtr = self::storageValuePtr($context, $storage);
        switch ($default->type) {
            case Variable::TYPE_NATIVE_LONG:
                $context->builder->call(
                    $context->lookupFunction('__value__writeLong'),
                    $destPtr,
                    $default->value
                );
                break;
            case Variable::TYPE_NATIVE_BOOL:
                $context->builder->call(
                    $context->lookupFunction('__value__writeLong'),
                    $destPtr,
                    $context->builder->zExt($default->value, $context->getTypeFromString('int64'))
                );
                break;
            case Variable::TYPE_NATIVE_DOUBLE:
                $context->builder->call(
                    $context->lookupFunction('__value__writeDouble'),
                    $destPtr,
                    $default->value
                );
                break;
            case Variable::TYPE_STRING:
                // fromLiteral string slots are `__string__**` (alloca); writeString wants `*`.
                $strPtr = JitStringArg::lower($context, $default, 'function-static string');
                $context->builder->call(
                    $context->lookupFunction('__value__writeString'),
                    $destPtr,
                    $strPtr
                );
                break;
            case Variable::TYPE_OBJECT:
                $context->builder->call(
                    $context->lookupFunction('__value__writeObject'),
                    $destPtr,
                    $context->helper->loadValue($default)
                );
                break;
            case Variable::TYPE_HASHTABLE:
                $ht = $context->helper->loadValue($default);
                $context->refcount->addref($ht);
                $context->builder->call(
                    $context->lookupFunction('__value__writeHashtable'),
                    $destPtr,
                    $ht
                );
                break;
            case Variable::TYPE_VALUE:
                $srcPtr = $context->helper->loadValue($default);
                if ($storage->functionStaticGlobal) {
                    $destPtr = JitValueBox::normalizeValuePtr(
                        $context,
                        $context->builder->load($storage->value)
                    );
                    JitValueBox::copyFromPointer($context, $destPtr, $srcPtr);
                    break;
                }
                JitValueBox::copyFromPointer($context, $storage->value, $srcPtr);
                break;
            default:
                if (($default->type & Variable::IS_NATIVE_ARRAY) !== 0) {
                    $ht = $context->helper->loadValue($default);
                    $context->refcount->addref($ht);
                    $context->builder->call(
                        $context->lookupFunction('__value__writeHashtable'),
                        $destPtr,
                        $ht
                    );
                    break;
                }
                throw new \LogicException(
                    'Unsupported function static default JIT type '.$default->type.' (#2286)'
                );
        }
    }

    private static function slotConst(Context $context, string $key): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $index = self::slotIndexForKey($key);

        return $i64->constInt($index, false);
    }

    private static function slotIndexForKey(string $key): int
    {
        if (!isset(self::$slotIndexByKey[$key])) {
            if (self::$nextSlotIndex >= 1024) {
                throw new \LogicException('Function static slot limit exceeded (#10173)');
            }
            self::$slotIndexByKey[$key] = self::$nextSlotIndex++;
        }

        return self::$slotIndexByKey[$key];
    }

    private static function storageValuePtr(Context $context, Variable $storage): Value
    {
        if ($storage->functionStaticGlobal) {
            return JitValueBox::normalizeValuePtr($context, $context->builder->load($storage->value));
        }

        return JitValueBox::pointer($context, $storage->value);
    }
}
