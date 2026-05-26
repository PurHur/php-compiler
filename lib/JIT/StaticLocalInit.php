<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Value;
use PHPCompiler\VM\Variable as VMVariable;

/**
 * Module-level boxed storage for function-local static variables (#2286).
 */
final class StaticLocalInit
{
    /** @var array<string, Value> */
    private static array $moduleGlobals = [];

    public static function globalFor(Context $context, string $storageKey, ?VMVariable $default = null): Value
    {
        if (!isset(self::$moduleGlobals[$storageKey])) {
            $ptrTy = $context->getTypeFromString('__value__*');
            $name = 'sl_'.substr(md5($storageKey), 0, 16);
            $global = $context->module->addGlobal($ptrTy, $name);
            $global->setInitializer($ptrTy->constNull());
            $restore = $context->builder->getInsertBlock();
            $context->builder->positionAtEnd($context->initBlock);
            $valueType = $context->getTypeFromString('__value__');
            $heapVal = $context->memory->malloc($valueType);
            $heapPtr = $context->builder->pointerCast($heapVal, $ptrTy);
            if (null !== $default) {
                self::writeDefault($context, $heapPtr, $default);
            } else {
                $context->builder->call(
                    $context->lookupFunction('__value__writeNull'),
                    $heapPtr
                );
            }
            $context->builder->store($heapPtr, $global);
            $context->builder->positionAtEnd($restore);
            self::$moduleGlobals[$storageKey] = $global;
        }

        return self::$moduleGlobals[$storageKey];
    }

    private static function writeDefault(Context $context, Value $heapPtr, VMVariable $default): void
    {
        switch ($default->type) {
            case VMVariable::TYPE_INTEGER:
                $context->builder->call(
                    $context->lookupFunction('__value__writeLong'),
                    $heapPtr,
                    $context->getTypeFromString('int64')->constInt($default->toInt(), false)
                );
                break;
            case VMVariable::TYPE_STRING:
                $str = $context->type->string->fromLiteral($default->toString());
                $context->builder->call(
                    $context->lookupFunction('__value__writeString'),
                    $heapPtr,
                    $context->helper->loadValue($str)
                );
                break;
            case VMVariable::TYPE_BOOLEAN:
                $context->builder->call(
                    $context->lookupFunction('__value__writeBool'),
                    $heapPtr,
                    $context->getTypeFromString('int1')->constInt($default->toBool() ? 1 : 0, false)
                );
                break;
            case VMVariable::TYPE_NULL:
                $context->builder->call(
                    $context->lookupFunction('__value__writeNull'),
                    $heapPtr
                );
                break;
            default:
                throw new \LogicException('Unsupported function static local default type for JIT');
        }
    }

    public static function loadVariable(Context $context, string $storageKey, ?VMVariable $default = null): Variable
    {
        $global = self::globalFor($context, $storageKey, $default);
        $loaded = $context->builder->load($global);
        $var = new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VALUE,
            $loaded
        );
        $var->staticPropertyGlobal = $global;
        $var->staticPropertyType = Variable::TYPE_VALUE;

        return $var;
    }
}
