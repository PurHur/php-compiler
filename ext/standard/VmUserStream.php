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

        $chunk = $result->toString();
        $state->position += \strlen($chunk);

        return $chunk;
    }

    /**
     * Userspace stream_write / fwrite (#25972).
     *
     * @return int|false bytes written
     */
    public static function write(int $handle, string $data, ?int $length = null): int|false
    {
        $state = self::$streams[$handle] ?? null;
        if (null === $state) {
            return false;
        }
        if (null !== $length && $length < 0) {
            return 0;
        }
        if (null !== $length && $length < \strlen($data)) {
            $data = \substr($data, 0, $length);
        }
        if ('' === $data) {
            return 0;
        }
        if (!$state->vm->hasInstanceMethod($state->wrapper->class, 'stream_write')) {
            return false;
        }
        $dataVar = new Variable();
        $dataVar->string($data);
        $result = $state->vm->invokeInstanceMethod($state->wrapper, 'stream_write', $dataVar)->resolveIndirect();
        if (Variable::TYPE_INTEGER === $result->type) {
            $written = $result->toInt();
            if ($written < 0) {
                return false;
            }
            $state->position += $written;

            return $written;
        }
        if (Variable::TYPE_BOOLEAN === $result->type) {
            return $result->toBool() ? \strlen($data) : false;
        }

        return false;
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
     * SEEK_CUR / SEEK_END are normalized to SEEK_SET before invoking the wrapper (php-src stream
     * layer converts SEEK_CUR; absolute SEEK_SET also avoids a VM $this/property glitch when
     * whence=SEEK_END is passed as a method arg after prior seeks).
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

        $absolute = self::absoluteSeekOffset($state, $offset, $whence);
        if (null === $absolute) {
            // SEEK_END without stream_stat size — invoke with SEEK_END directly.
            return self::seekRaw($state, $offset, \SEEK_END);
        }
        if (false === $absolute) {
            return -1;
        }

        return self::seekSet($state, $absolute);
    }

    /**
     * @return int|false|null Absolute SEEK_SET offset; null = caller should seekRaw(SEEK_END);
     *                       false = failure
     */
    private static function absoluteSeekOffset(UserStreamState $state, int $offset, int $whence): int|false|null
    {
        if (\SEEK_SET === $whence) {
            return $offset;
        }
        if (\SEEK_CUR === $whence) {
            $pos = self::tellState($state);
            if (false === $pos) {
                return false;
            }

            return $pos + $offset;
        }
        if (\SEEK_END === $whence) {
            $size = self::statSize($state);
            if (false === $size) {
                return null;
            }

            return $size + $offset;
        }

        return false;
    }

    /** @return int 0 on success, -1 on failure */
    private static function seekSet(UserStreamState $state, int $absolute): int
    {
        return self::seekRaw($state, $absolute, \SEEK_SET);
    }

    /** @return int 0 on success, -1 on failure */
    private static function seekRaw(UserStreamState $state, int $offset, int $whence): int
    {
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
        $state->position = $tellResult->toInt();

        return 0;
    }

    /** @return int|false */
    private static function tellState(UserStreamState $state): int|false
    {
        if (!$state->vm->hasInstanceMethod($state->wrapper->class, 'stream_tell')) {
            return $state->position;
        }
        $result = $state->vm->invokeInstanceMethod($state->wrapper, 'stream_tell')->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $result->type) {
            return false;
        }
        $state->position = $result->toInt();

        return $state->position;
    }

    /** @return int|false size from stream_stat, or false */
    private static function statSize(UserStreamState $state): int|false
    {
        if (!$state->vm->hasInstanceMethod($state->wrapper->class, 'stream_stat')) {
            return false;
        }
        $result = $state->vm->invokeInstanceMethod($state->wrapper, 'stream_stat')->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $result->type) {
            return false;
        }
        $ht = $result->toArray();
        $sizeVar = $ht->find('size') ?? $ht->findIndex(7);
        if (null === $sizeVar) {
            return false;
        }
        $sizeVar = $sizeVar->resolveIndirect();
        if (Variable::TYPE_INTEGER === $sizeVar->type) {
            return $sizeVar->toInt();
        }
        if (Variable::TYPE_FLOAT === $sizeVar->type) {
            return (int) $sizeVar->toFloat();
        }
        if (Variable::TYPE_STRING === $sizeVar->type && \is_numeric($sizeVar->toString())) {
            return (int) $sizeVar->toString();
        }

        return false;
    }

    /**
     * Userspace ftell — php-src uses stream->position (updated on read/write/seek), not a
     * direct stream_tell call (#25971 / #25972).
     *
     * @return int|false
     */
    public static function tell(int $handle): int|false
    {
        $state = self::$streams[$handle] ?? null;
        if (null === $state) {
            return false;
        }

        return $state->position;
    }

    /**
     * url_stat for file_exists()/filesize()/stat() on custom protocols (#25973).
     *
     * php-src main/streams/userspace.c — php_userstreamop_stat / url_stat
     *
     * @return array<int|string, int>|false
     */
    public static function urlStat(string $uri, int $flags = 0): array|false
    {
        if (!VmStreamWrapperRegistry::isCustomProtocol($uri)) {
            return false;
        }
        $ctx = \PHPCompiler\Web\Superglobals::getActiveContext();
        if (null === $ctx) {
            return false;
        }
        $protocol = VmStreamWrapperRegistry::parseProtocol($uri);
        if (null === $protocol) {
            return false;
        }
        $className = VmStreamWrapperRegistry::lookupClass($protocol);
        if (null === $className) {
            return false;
        }
        $wrapper = self::instantiateWrapper($ctx->runtime->vm, $ctx, $className);
        if (null === $wrapper) {
            return false;
        }
        if (!$ctx->runtime->vm->hasInstanceMethod($wrapper->class, 'url_stat')) {
            return false;
        }
        $pathVar = new Variable();
        $pathVar->string($uri);
        $flagsVar = new Variable();
        $flagsVar->int($flags);
        $result = $ctx->runtime->vm->invokeInstanceMethod(
            $wrapper,
            'url_stat',
            $pathVar,
            $flagsVar
        )->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $result->type) {
            return false;
        }

        return self::statArrayFromHashTable($result->toArray());
    }

    /**
     * @return array<int|string, int>|false
     */
    private static function statArrayFromHashTable(\PHPCompiler\VM\HashTable $ht): array|false
    {
        $out = [];
        $keys = [
            'dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'rdev', 'size',
            'atime', 'mtime', 'ctime', 'blksize', 'blocks',
        ];
        foreach ($keys as $i => $key) {
            $var = $ht->find($key) ?? $ht->findIndex($i);
            if (null === $var) {
                continue;
            }
            $var = $var->resolveIndirect();
            if (Variable::TYPE_INTEGER === $var->type) {
                $out[$key] = $var->toInt();
                $out[$i] = $var->toInt();
            } elseif (Variable::TYPE_FLOAT === $var->type) {
                $v = (int) $var->toFloat();
                $out[$key] = $v;
                $out[$i] = $v;
            } elseif (Variable::TYPE_STRING === $var->type && \is_numeric($var->toString())) {
                $v = (int) $var->toString();
                $out[$key] = $v;
                $out[$i] = $v;
            }
        }
        // url_stat returning an array (even partial) means the path exists (php-src).
        if ([] === $out) {
            // Preserve existence with a zeroed size so filesize can still fail honestly if missing.
            $out['size'] = 0;
            $out[7] = 0;
        }

        return $out;
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
    public int $position = 0;

    public function __construct(
        public ObjectEntry $wrapper,
        public string $uri,
        public string $protocol,
        public VM $vm,
    ) {
    }
}
