<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Lazy init for function-local static variables (issue #2286).
 */
final class FunctionStaticHelper
{
    /** @var array<string, Value> */
    private static array $initFlags = [];

    public static function emitLazyInit(Context $context, string $key, Variable $storage, Variable $default): void
    {
        if (!isset(self::$initFlags[$key])) {
            $i8 = $context->getTypeFromString('int8');
            $flagName = 'phpc_fn_static_init_'.substr(hash('sha256', $key), 0, 16);
            $flag = $context->module->addGlobal($i8, $flagName);
            $flag->setInitializer($i8->constInt(0, false));
            self::$initFlags[$key] = $flag;
        }
        $i8 = $context->getTypeFromString('int8');
        $flag = self::$initFlags[$key];
        $loaded = $context->builder->load($flag);
        $isZero = $context->builder->icmp(
            Builder::INT_EQ,
            $loaded,
            $i8->constInt(0, false)
        );
        $initBlock = BasicBlockHelper::append($context, 'fn_static_init');
        $doneBlock = BasicBlockHelper::append($context, 'fn_static_done');
        $context->builder->branchIf($isZero, $initBlock, $doneBlock);
        $context->builder->positionAtEnd($initBlock);
        self::writeDefault($context, $storage, $default);
        $context->builder->store($i8->constInt(1, false), $flag);
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($doneBlock);
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
                $context->builder->call(
                    $context->lookupFunction('__value__writeString'),
                    $destPtr,
                    $default->value
                );
                break;
            case Variable::TYPE_VALUE:
                if ($storage->functionStaticGlobal) {
                    throw new \LogicException(
                        'Boxed function static default on global storage not supported yet (#3778)'
                    );
                }
                $srcPtr = $context->helper->loadValue($default);
                JitValueBox::copyFromPointer($context, $storage->value, $srcPtr);
                break;
            default:
                throw new \LogicException(
                    'Unsupported function static default JIT type '.$default->type.' (#2286)'
                );
        }
    }

    private static function storageValuePtr(Context $context, Variable $storage): Value
    {
        if ($storage->functionStaticGlobal) {
            return JitValueBox::normalizeValuePtr($context, $context->builder->load($storage->value));
        }

        return JitValueBox::pointer($context, $storage->value);
    }
}
