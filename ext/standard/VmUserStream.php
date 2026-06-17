<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Userspace stream wrapper dispatch (php-src main/streams/userspace.c; #3383).
 */
final class VmUserStream
{
    /** @var array<int, UserStreamState> */
    private static array $streams = [];

    private static int $nextHandleId = 0;

    public static function open(VM $vm, Context $ctx, string $uri, string $mode): int|false
    {
        $protocol = VmStreamWrapperRegistry::parseProtocol($uri);
        if (null === $protocol) {
            return false;
        }
        $className = VmStreamWrapperRegistry::lookupClass($protocol);
        if (null === $className) {
            return false;
        }
        $object = self::instantiateWrapper($vm, $ctx, $className);
        if (null === $object) {
            return false;
        }
        if (!self::callStreamOpen($vm, $object, $uri, $mode)) {
            return false;
        }
        $id = ++self::$nextHandleId;
        self::$streams[$id] = new UserStreamState($object, $uri, $protocol, $vm);

        return $id;
    }

    public static function read(int $handle, int $length): string|false
    {
        $state = self::$streams[$handle] ?? null;
        if (null === $state || $length < 0) {
            return false;
        }
        $countVar = new Variable();
        $countVar->int($length);
        $result = $state->vm->invokeInstanceMethod($state->wrapper, 'stream_read', $countVar)->resolveIndirect();
        if (Variable::TYPE_STRING !== $result->type) {
            return false;
        }

        return $result->toString();
    }

    public static function feof(int $handle): bool
    {
        $state = self::$streams[$handle] ?? null;
        if (null === $state) {
            return true;
        }
        if (!$state->vm->hasInstanceMethod($state->wrapper->class, 'stream_eof')) {
            return false;
        }
        $result = $state->vm->invokeInstanceMethod($state->wrapper, 'stream_eof')->resolveIndirect();

        return Variable::TYPE_BOOLEAN === $result->type && $result->toBool();
    }

    public static function close(int $handle): bool
    {
        $state = self::$streams[$handle] ?? null;
        if (null === $state) {
            return false;
        }
        unset(self::$streams[$handle]);
        if ($state->vm->hasInstanceMethod($state->wrapper->class, 'stream_close')) {
            $state->vm->invokeInstanceMethod($state->wrapper, 'stream_close');
        }

        return true;
    }

    public static function readAll(int $handle): string|false
    {
        $chunks = [];
        // php-src userspace.c: stream_read then stream_eof; eof after data ends the read (#9162).
        while (true) {
            $chunk = self::read($handle, 8192);
            if (false === $chunk) {
                return false;
            }
            if ('' === $chunk) {
                break;
            }
            $chunks[] = $chunk;
            if (self::feof($handle)) {
                break;
            }
        }

        return \implode('', $chunks);
    }

    public static function isValidHandle(int $handle): bool
    {
        return isset(self::$streams[$handle]);
    }

    public static function protocolForHandle(int $handle): string
    {
        return self::$streams[$handle]->protocol ?? 'Unknown';
    }

    public static function uriForHandle(int $handle): string
    {
        return self::$streams[$handle]->uri ?? '';
    }

    /** @return list<int> */
    public static function openHandleIds(): array
    {
        return \array_keys(self::$streams);
    }

    private static function instantiateWrapper(VM $vm, Context $ctx, string $className): ?ObjectEntry
    {
        $lc = \strtolower($className);
        if (!isset($ctx->classes[$lc])) {
            $ctx->autoloadClass($className);
        }
        if (!isset($ctx->classes[$lc])) {
            return null;
        }
        $class = $ctx->classes[$lc];
        if ($class->isEnum || $class->isAbstract || $class->isInterface) {
            return null;
        }
        try {
            VM\ClassValidator::assertInstantiable($class);
        } catch (\Error) {
            return null;
        }
        $object = new ObjectEntry($class);
        $vm->initInstancePropertyDefaults($object);
        $object->constructed = true;

        return $object;
    }

    private static function callStreamOpen(
        VM $vm,
        ObjectEntry $wrapper,
        string $uri,
        string $mode
    ): bool {
        if (!$vm->hasInstanceMethod($wrapper->class, 'stream_open')) {
            return false;
        }
        $pathVar = new Variable();
        $pathVar->string($uri);
        $modeVar = new Variable();
        $modeVar->string($mode);
        $optionsVar = new Variable();
        $optionsVar->int(0);
        $openedVar = new Variable();
        $openedVar->string('');
        $result = $vm->invokeInstanceMethod(
            $wrapper,
            'stream_open',
            $pathVar,
            $modeVar,
            $optionsVar,
            $openedVar
        )->resolveIndirect();
        if (Variable::TYPE_BOOLEAN === $result->type) {
            return $result->toBool();
        }

        return false;
    }
}

/** @internal */
final class UserStreamState
{
    public function __construct(
        public ObjectEntry $wrapper,
        public string $uri,
        public string $protocol,
        public VM $vm,
    ) {
    }
}
