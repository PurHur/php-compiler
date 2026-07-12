<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** HashTable::updateIndex() for nested php-in-PHP JIT helpers (#16075 / VmPregMatches). */
final class HashTableUpdateIndex implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (count($args) < 3) {
            throw new \LogicException('updateIndex() requires HashTable receiver, int index, and Variable value');
        }
        $ht = HashTableNestedReceiver::hashtableFromReceiver($context, $args[0]);
        $index = self::indexFromArg($context, $args[1]);
        HashTableHelper::setAtIndex($context, $ht, $index, $args[2]);

        return HashTableNestedReceiver::nullVariableResult($context);
    }

    private static function indexFromArg(Context $context, Variable $indexArg): Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        if (Variable::TYPE_NATIVE_LONG === $indexArg->type) {
            return $context->builder->intCast($context->helper->loadValue($indexArg), $sizeT);
        }
        if (Variable::TYPE_VALUE === $indexArg->type || null !== $indexArg->valueBoxAliasPtr) {
            $long = $context->builder->call(
                $context->lookupFunction('__value__readLong'),
                $context->helper->loadValue($indexArg)
            );

            return $context->builder->intCast($long, $sizeT);
        }

        return $context->builder->intCast($context->helper->loadValue($indexArg), $sizeT);
    }
}
