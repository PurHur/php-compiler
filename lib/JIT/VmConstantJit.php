<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCfg\Operand\Literal;
use PHPCompiler\VM\Variable as VmVariable;
use PHPTypes\Type;

/**
 * Lower compile-time {@see VmVariable} slots from {@see Block::$constants} to JIT variables.
 */
final class VmConstantJit
{
    public static function toVariable(Context $context, VmVariable $vm): Variable
    {
        switch ($vm->type) {
            case VmVariable::TYPE_INTEGER:
                return Variable::fromConstantInt($context, $vm->toInt());
            case VmVariable::TYPE_STRING:
                $lit = new Literal($vm->toString());
                $lit->type = Type::string();

                return Variable::fromLiteral($context, $lit);
            case VmVariable::TYPE_FLOAT:
                $lit = new Literal($vm->toFloat());
                $lit->type = Type::float();

                return Variable::fromLiteral($context, $lit);
            case VmVariable::TYPE_BOOLEAN:
                $lit = new Literal($vm->toBool());
                $lit->type = Type::bool();

                return Variable::fromLiteral($context, $lit);
            case VmVariable::TYPE_NULL:
                // Allocate a real __value__ null box — a null __value__* KIND_VALUE crashes
                // standalone AOT on post-call delref (LimitIterator literal null args; #31621).
                // Match Variable::fromLiteral(TYPE_NULL) / #27623 rematerialize intent.
                $slot = JitValueBox::alloc($context);
                $context->builder->call(
                    $context->lookupFunction('__value__writeNull'),
                    JitValueBox::pointer($context, $slot)
                );
                $nullVar = new Variable(
                    $context,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VARIABLE,
                    $slot
                );
                $nullVar->isNullConstant = true;

                return $nullVar;
            case VmVariable::TYPE_ARRAY:
                return self::arrayVariable($context, $vm);
            default:
                throw new \LogicException('Unsupported compile-time constant for JIT (vm type '.$vm->type.')');
        }
    }

    private static function arrayVariable(Context $context, VmVariable $vm): Variable
    {
        $ht = $vm->toArray();
        $jitHt = HashTableHelper::alloc($context);
        $var = new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            $jitHt
        );
        if (0 === $ht->getNumElements()) {
            return $var;
        }
        foreach ($ht->iterateKeyed(true) as [$key, $value]) {
            HashTableHelper::addElement(
                $context,
                $var,
                self::toVariable($context, $value),
                self::toVariable($context, $key)
            );
        }

        return $var;
    }
}
