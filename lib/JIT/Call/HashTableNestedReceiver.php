<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** Extract {@see __hashtable__*} from nested-JIT HashTable receivers (#14601). */
final class HashTableNestedReceiver
{
    public static function hashtableFromReceiver(Context $context, Variable $receiver): Value
    {
        $htPtrTy = $context->getTypeFromString('__hashtable__*');
        if (Variable::TYPE_HASHTABLE === $receiver->type) {
            return HashTableHelper::loadHashtablePointer($context, $receiver);
        }
        $objPtr = $context->helper->loadValue($receiver);

        return $context->builder->bitcast($objPtr, $htPtrTy);
    }
}
