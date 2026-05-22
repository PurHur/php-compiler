<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\SuperglobalInit;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class VmContextEnsureSuperglobal implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (count($args) < 1) {
            throw new \LogicException('Context::ensureSuperglobal() expects at least one argument');
        }
        $nameArg = $args[count($args) - 1];
        $name = $nameArg->compileTimeString;
        if (null === $name) {
            return $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        }
        if (!isset(SuperglobalInit::$globals[$name])) {
            throw new \LogicException("Superglobal not initialized for JIT: {$name}");
        }
        $global = SuperglobalInit::$globals[$name];
        $loaded = $context->builder->load($global);
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $null = $htPtr->constNull();
        $isNull = $context->builder->icmp(Builder::INT_EQ, $loaded, $null);
        $alloc = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $ht = $context->builder->select($isNull, $alloc, $loaded);
        $context->builder->store($ht, $global);

        return $ht;
    }
}
