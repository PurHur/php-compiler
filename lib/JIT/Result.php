<?php

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\JIT;

use PHPCompiler\Func;
use PHPCompiler\Handler;

use PHPLLVM;
use FFI;

class Result {
    private PHPLLVM\ExecutionEngine $engine;
    private int $loadType;

    public function __construct(PHPLLVM\ExecutionEngine $engine, int $loadType) {
        $this->engine = $engine;
        $this->loadType = $loadType;
        if ($loadType !== Builtin::LOAD_TYPE_IMPORT) {
            // Call the initialization function!
            $cb = $this->getCallable('__init__', 'void(*)()');
            $cb();
        }
    }

    public function __destruct() {
        if ($this->loadType !== Builtin::LOAD_TYPE_IMPORT) {
            // Call the initialization function!
            $cb = $this->getCallable('__shutdown__', 'void(*)()');
            $cb();
        }
    }

    public function getFunc(string $publicName, string $funcName, string $callbackType): Func {
        if (self::selfHostAotStubEnabled()) {
            return new Func\JIT($publicName, self::selfHostNoopCallable(), $this);
        }

        return new Func\JIT(
            $publicName,
            $this->getCallable($funcName, $callbackType),
            $this
        );
    }

    public function getHandler(string $funcName, string $callbackType): Handler {
        if (self::selfHostAotStubEnabled()) {
            return new Func\JIT($funcName, self::selfHostNoopCallable(), $this);
        }

        return new Func\JIT(
            $funcName,
            $this->getCallable($funcName, $callbackType),
            $this
        );
    }

    public function getCallable(string $funcName, string $callbackType): callable {
        if (self::selfHostAotStubEnabled()) {
            return self::selfHostNoopCallable();
        }

        $address = $this->engine->getFunctionAddress($funcName);
        $code = FFI::new('uintptr_t');
        $code->cdata = $address;
        $cb = FFI::new($callbackType);
        FFI::memcpy(
            FFI::addr($cb),
            FFI::addr($code),
            FFI::sizeof($cb)
        );

        return $cb;
    }

    /** Self-host AOT bundles skip FFI pointer casts; use no-op handlers (#816, #557). */
    private static function selfHostAotStubEnabled(): bool
    {
        $flag = getenv('PHP_COMPILER_SELFHOST_AOT');

        return '1' === $flag || 'true' === strtolower((string) $flag);
    }

    /** @noinspection PhpUnused — invoked as callable target for self-host AOT stubs */
    public static function selfHostNoopHandler(): void
    {
    }

    private static function selfHostNoopCallable(): callable
    {
        return [self::class, 'selfHostNoopHandler'];
    }
}
