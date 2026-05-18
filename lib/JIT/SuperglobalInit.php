<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPCompiler\Web\Superglobals;

/**
 * Initialize LLVM globals for CGI superglobals from the VM context at compile time.
 */
final class SuperglobalInit
{
    /** @var array<string, \PHPLLVM\Value> */
    public static array $globals = [];

    public static function declareRefresh(Context $context): void
    {
        $signature = $context->context->functionType($context->context->voidType(), false);
        $fn = $context->module->addFunction('__superglobals__refresh', $signature);
        $context->registerFunction('__superglobals__refresh', $fn);
    }

    public static function initialize(Context $context): void
    {
        self::$globals = [];
        $oldBuilder = $context->builder;
        $context->builder->positionAtEnd($context->initBlock);

        $htPtr = $context->getTypeFromString('__hashtable__*');
        foreach (Superglobals::NAMES as $name) {
            $globalName = 'sg_'.substr($name, 1);
            $global = $context->module->addGlobal($htPtr, $globalName);
            $global->setInitializer($htPtr->constNull());

            $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
            self::populateFromVm($context, $ht, $name);
            $context->builder->store($ht, $global);
            self::$globals[$name] = $global;
        }

        $context->builder = $oldBuilder;
    }

    private static function populateFromVm(Context $context, \PHPLLVM\Value $ht, string $name): void
    {
        $vmVar = $context->runtime->vmContext->getSuperglobal($name);
        if (null === $vmVar || VMVariable::TYPE_ARRAY !== $vmVar->type) {
            return;
        }
        $table = $vmVar->toArray();
        if (!$table instanceof HashTable) {
            return;
        }
        self::populateHashTableFromVm($context, $ht, $table);
    }

    private static function populateHashTableFromVm(
        Context $context,
        \PHPLLVM\Value $ht,
        HashTable $table
    ): void {
        $setString = $context->lookupFunction('__hashtable__setStringKeyString');
        $setHashtable = $context->lookupFunction('__hashtable__setStringKeyHashtable');
        foreach ($table->iterateKeyed(true) as [$keyVar, $valueVar]) {
            $resolved = $valueVar->resolveIndirect();
            if (VMVariable::TYPE_INTEGER === $keyVar->type) {
                if (VMVariable::TYPE_STRING !== $resolved->type) {
                    continue;
                }
                $str = $context->builder->load(
                    $context->constantStringFromString($resolved->toString())
                );
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setStringAt'),
                    $ht,
                    $context->getTypeFromString('size_t')->constInt($keyVar->toInt(), false),
                    $str
                );

                continue;
            }
            if (VMVariable::TYPE_STRING !== $keyVar->type) {
                continue;
            }
            $key = $context->builder->load(
                $context->constantStringFromString($keyVar->toString())
            );
            if (VMVariable::TYPE_STRING === $resolved->type) {
                $str = $context->builder->load(
                    $context->constantStringFromString($resolved->toString())
                );
                $context->builder->call($setString, $ht, $key, $str);
            } elseif (VMVariable::TYPE_ARRAY === $resolved->type) {
                $nested = $resolved->toArray();
                if ($nested instanceof HashTable) {
                    $child = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
                    self::populateHashTableFromVm($context, $child, $nested);
                    $context->builder->call($setHashtable, $ht, $key, $child);
                }
            }
        }
    }

    public static function load(Context $context, string $name): Variable
    {
        if (!isset(self::$globals[$name])) {
            throw new \LogicException("Superglobal not initialized for JIT: {$name}");
        }

        $var = new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            $context->builder->load(self::$globals[$name])
        );
        $var->superglobalName = $name;

        return $var;
    }

    /**
     * For AOT: resolve isset($superglobal['key']) from VM data baked in at compile time.
     */
    public static function compileTimeOffsetIsSet(Context $context, string $superglobalName, string $key): ?bool
    {
        $vmVar = $context->runtime->vmContext->getSuperglobal($superglobalName);
        if (null === $vmVar || VMVariable::TYPE_ARRAY !== $vmVar->type) {
            return false;
        }
        $table = $vmVar->toArray();
        if (!$table instanceof HashTable) {
            return false;
        }
        $keyVar = new VMVariable();
        $keyVar->string($key);

        return $table->offsetIsSet($keyVar);
    }

    /**
     * For AOT: read a string value from a superglobal at compile time (e.g. $_GET['name']).
     */
    public static function compileTimeReadString(
        Context $context,
        string $superglobalName,
        string $key
    ): ?\PHPLLVM\Value {
        if (!self::compileTimeOffsetIsSet($context, $superglobalName, $key)) {
            return null;
        }
        $vmVar = $context->runtime->vmContext->getSuperglobal($superglobalName);
        $table = $vmVar->toArray();
        if (!$table instanceof HashTable) {
            return null;
        }
        $stored = $table->find($key);
        if (null === $stored) {
            return null;
        }
        $valueVar = $stored->resolveIndirect();
        if (VMVariable::TYPE_STRING !== $valueVar->type) {
            return null;
        }

        return $context->builder->load(
            $context->constantStringFromString($valueVar->toString())
        );
    }
}
