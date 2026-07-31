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
        $id = VmFs::allocateStreamHandleId();
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

    /**
     * stream_get_contents() on userspace handles (php-src file.c + userspace.c; #25970).
     *
     * @return string|false
     */
    public static function streamGetContents(int $handle, int $maxlength = -1, int $offset = -1): string|false
    {
        if (!isset(self::$streams[$handle])) {
            return false;
        }
        // php-src file.c: only offset >= 0 seeks; negative keeps current position (#23190).
        if ($offset >= 0 && 0 !== self::seek($handle, $offset, \SEEK_SET)) {
            VmFs::warnStreamGetContentsSeekFailed($offset);

            return false;
        }
        if (0 === $maxlength) {
            return '';
        }
        if ($maxlength < 0) {
            return self::readAll($handle);
        }

        $chunks = [];
        $remaining = $maxlength;
        while ($remaining > 0) {
            $chunk = self::read($handle, $remaining);
            if (false === $chunk) {
                return false;
            }
            if ('' === $chunk) {
                break;
            }
            $chunks[] = $chunk;
            $remaining -= \strlen($chunk);
            if (self::feof($handle)) {
                break;
            }
        }

        return \implode('', $chunks);
    }

    /**
     * Userspace stream_seek — used by stream_get_contents(offset) and fseek (#25970 / #25971).
     *
     * php-src userspace.c php_userstreamop_seek: after a successful stream_seek, stream_tell
     * must return the new offset; missing stream_tell → E_WARNING and seek failure.
     *
     * @return int 0 on success, -1 on failure (php-src fseek convention)
     */
    public static function seek(int $handle, int $offset, int $whence = \SEEK_SET): int
    {
        $state = self::$streams[$handle] ?? null;
        if (null === $state) {
            return -1;
        }
        if (!$state->vm->hasInstanceMethod($state->wrapper->class, 'stream_seek')) {
            return -1;
        }
        $offsetVar = new Variable();
        $offsetVar->int($offset);
        $whenceVar = new Variable();
        $whenceVar->int($whence);
        $result = $state->vm->invokeInstanceMethod(
            $state->wrapper,
            'stream_seek',
            $offsetVar,
            $whenceVar
        )->resolveIndirect();
        $seekOk = false;
        if (Variable::TYPE_BOOLEAN === $result->type) {
            $seekOk = $result->toBool();
        } elseif (Variable::TYPE_INTEGER === $result->type) {
            $seekOk = 0 === $result->toInt();
        }
        if (!$seekOk) {
            return -1;
        }
        if (!$state->vm->hasInstanceMethod($state->wrapper->class, 'stream_tell')) {
            $className = $state->wrapper->class->name;
            $message = $className.'::stream_tell is not implemented!';
            $vm = $state->vm;
            $frame = $vm->builtinHandlerFrame();
            if (null === $frame) {
                $frames = $vm->context->runStackFrames();
                $frame = [] !== $frames ? $frames[0] : null;
            }
            $vm->context->errors->triggerError(
                $message,
                \PHPCompiler\ErrorReporter::E_WARNING,
                null,
                $vm->context,
                $frame
            );

            return -1;
        }
        $tellResult = $state->vm->invokeInstanceMethod($state->wrapper, 'stream_tell')->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $tellResult->type) {
            return -1;
        }

        return 0;
    }

    /**
     * Userspace stream_tell (#25971).
     *
     * @return int|false
     */
    public static function tell(int $handle): int|false
    {
        $state = self::$streams[$handle] ?? null;
        if (null === $state) {
            return false;
        }
        if (!$state->vm->hasInstanceMethod($state->wrapper->class, 'stream_tell')) {
            return false;
        }
        $result = $state->vm->invokeInstanceMethod($state->wrapper, 'stream_tell')->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $result->type) {
            return false;
        }

        return $result->toInt();
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

    public static function instantiateWrapper(VM $vm, Context $ctx, string $className): ?ObjectEntry
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
