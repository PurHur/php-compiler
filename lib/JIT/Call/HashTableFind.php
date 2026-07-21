<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** HashTable::find() for nested php-in-PHP JIT helpers (#21849, SessionStorageJitHelper::readCookieId). */
final class HashTableFind implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('find() requires HashTable receiver and string key');
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'ht_find_cont');
        $ht = HashTableNestedReceiver::hashtableFromReceiver($context, $args[0]);
        $keyStr = JitStringArg::stringPtrFromVariable($context, $args[1]);
        $valPtr = $context->builder->call(
            $context->lookupFunction('__hashtable__readStringKeyValue'),
            $ht,
            $keyStr
        );

        $slot = JitValueBox::alloc($context);
        $destPtr = JitValueBox::pointer($context, $slot);
        $nullBb = BasicBlockHelper::append($context, 'ht_find_null');
        $copyBb = BasicBlockHelper::append($context, 'ht_find_copy');
        $doneBb = BasicBlockHelper::append($context, 'ht_find_done');
        $isNullPtr = $context->builder->icmp(
            Builder::INT_EQ,
            $valPtr,
            $valPtr->typeOf()->constNull()
        );
        $context->builder->branchIf($isNullPtr, $nullBb, $copyBb);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $destPtr
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($copyBb);
        JitValueBox::copyFromPointer($context, $slot, $valPtr);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $slot;
    }
}
