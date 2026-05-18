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
        $setString = $context->lookupFunction('__hashtable__setStringKeyString');
        foreach ($table->iterateKeyed(true) as [$keyVar, $valueVar]) {
            if (VMVariable::TYPE_STRING !== $keyVar->type) {
                continue;
            }
            $key = $context->builder->load(
                $context->constantStringFromString($keyVar->toString())
            );
            if (VMVariable::TYPE_STRING === $valueVar->type) {
                $str = $context->builder->load(
                    $context->constantStringFromString($valueVar->toString())
                );
                $context->builder->call($setString, $ht, $key, $str);
            }
        }
    }

    public static function load(Context $context, string $name): Variable
    {
        if (!isset(self::$globals[$name])) {
            throw new \LogicException("Superglobal not initialized for JIT: {$name}");
        }

        return new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            $context->builder->load(self::$globals[$name])
        );
    }
}
