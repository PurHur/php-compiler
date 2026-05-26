<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin;
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

    /** Superglobals fully repopulated by __superglobals__refresh in standalone AOT binaries. */
    private const STANDALONE_REFRESHED = [
        '_GET',
        '_POST',
        '_REQUEST',
        '_SERVER',
        '_FILES',
        '_COOKIE',
    ];

    /** $_SERVER keys repopulated by __superglobals__refresh (issue #201, #235, #296, #302, #295, #314, #453, #2257). */
    private const RUNTIME_SERVER_KEYS = [
        'REQUEST_METHOD',
        'REQUEST_SCHEME',
        'HTTPS',
        'SERVER_PORT',
        'SERVER_NAME',
        'SERVER_PROTOCOL',
        'DOCUMENT_ROOT',
        'SCRIPT_NAME',
        'SCRIPT_FILENAME',
        'PHP_SELF',
        'PATH_INFO',
        'REQUEST_URI',
        'CONTENT_LENGTH',
        'CONTENT_TYPE',
        'REMOTE_ADDR',
        'REMOTE_PORT',
    ];

    public static function declareRefresh(Context $context): void
    {
        if (Builtin::LOAD_TYPE_IMPORT === $context->loadType) {
            return;
        }
        $signature = $context->context->functionType($context->context->voidType(), false);
        $fn = $context->module->addFunction('__superglobals__refresh', $signature);
        $context->registerFunction('__superglobals__refresh', $fn);
    }

    /**
     * MCJIT embed: LLVM body that repopulates sg_* from the VM (issue #642, #2055).
     * Standalone AOT links {@see __superglobals__refresh} from C runtime instead.
     */
    public static function implementRefresh(Context $context): void
    {
        if (Builtin::LOAD_TYPE_EMBED !== $context->loadType) {
            return;
        }
        $fn = $context->lookupFunction('__superglobals__refresh');
        $entry = $fn->appendBasicBlock('entry');
        $oldBuilder = $context->builder;
        $context->builder->positionAtEnd($entry);
        foreach (self::STANDALONE_REFRESHED as $name) {
            if (!isset(self::$globals[$name])) {
                continue;
            }
            $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
            self::populateFromVm($context, $ht, $name);
            $context->builder->store($ht, self::$globals[$name]);
        }
        $context->builder->returnVoid();
        $context->builder = $oldBuilder;
    }

    /**
     * Re-copy VM superglobals into LLVM sg_* globals (MCJIT embed, issue #642).
     */
    public static function refreshFromVm(Context $context): void
    {
        if (null === $context->jitResult()) {
            return;
        }
        $refresh = $context->jitResult()->getCallable('__superglobals__refresh', 'void(*)()');
        $refresh();
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
     * Standalone AOT binaries refresh these superglobals at runtime; compile-time folds must not bypass writes.
     */
    private static function isStandaloneRuntimeSuperglobal(Context $context, string $superglobalName): bool
    {
        return self::requiresRuntimeOffsetIsSet($context, $superglobalName);
    }

    /**
     * sg_* tables repopulated per run (MCJIT embed #642, standalone AOT refresh).
     * isset/empty on these must not fold from compile-time VM snapshots.
     */
    public static function requiresRuntimeOffsetIsSet(Context $context, string $superglobalName): bool
    {
        if (!in_array($superglobalName, self::STANDALONE_REFRESHED, true)) {
            return false;
        }

        return Builtin::LOAD_TYPE_STANDALONE === $context->loadType
            || Builtin::LOAD_TYPE_EMBED === $context->loadType;
    }

    /**
     * For AOT: resolve isset($superglobal['key']) from VM data baked in at compile time.
     */
    public static function compileTimeOffsetIsSet(Context $context, string $superglobalName, string $key): ?bool
    {
        if (self::isStandaloneRuntimeSuperglobal($context, $superglobalName)) {
            return null;
        }
        if ('_REQUEST' === $superglobalName) {
            return null;
        }
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
        if (self::isStandaloneRuntimeSuperglobal($context, $superglobalName)) {
            return null;
        }
        // $_REQUEST is rebuilt each run from $_GET + $_POST (issue #291).
        if ('_REQUEST' === $superglobalName) {
            return null;
        }
        if ('_SERVER' === $superglobalName && in_array($key, self::RUNTIME_SERVER_KEYS, true)) {
            return null;
        }
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
