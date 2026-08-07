<?php

declare(strict_types=1);

namespace PHPCompiler\ext\memcached;

use PHPCompiler\Frame;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * Memcached depth methods beyond MVP (#27874) — add/replace/append/prepend,
 * getMulti/setMulti/deleteMulti, increment/decrement, flush, touch.
 */
final class MemcachedAdd extends MemcachedClassMethod
{
    public function __construct()
    {
        parent::__construct('add');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Memcached::add()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'Memcached::add() expects at least 2 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $key = $this->stringArg($frame->calledArgs[1], 'Memcached::add', 0, 'key');
        $value = VmMemcached::coerceValueToString($frame->calledArgs[2], 'Memcached::add', 1, 'value');
        $expiration = \count($frame->calledArgs) >= 4
            ? $this->intArg($frame->calledArgs[3], 'Memcached::add', 2, 'expiration', 0)
            : 0;
        $state = VmMemcached::state($receiver);
        $socket = VmMemcached::ensureSocket($receiver, 'Memcached::add');
        if (null === $socket) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $result = VmMemcachedNative::add($socket, $state->prefixKey.$key, $value, $expiration);
        $state->resultCode = $result['code'];
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($result['ok']);
        }
    }
}

final class MemcachedReplace extends MemcachedClassMethod
{
    public function __construct()
    {
        parent::__construct('replace');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Memcached::replace()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'Memcached::replace() expects at least 2 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $key = $this->stringArg($frame->calledArgs[1], 'Memcached::replace', 0, 'key');
        $value = VmMemcached::coerceValueToString($frame->calledArgs[2], 'Memcached::replace', 1, 'value');
        $expiration = \count($frame->calledArgs) >= 4
            ? $this->intArg($frame->calledArgs[3], 'Memcached::replace', 2, 'expiration', 0)
            : 0;
        $state = VmMemcached::state($receiver);
        $socket = VmMemcached::ensureSocket($receiver, 'Memcached::replace');
        if (null === $socket) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $result = VmMemcachedNative::replace($socket, $state->prefixKey.$key, $value, $expiration);
        $state->resultCode = $result['code'];
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($result['ok']);
        }
    }
}

final class MemcachedAppend extends MemcachedClassMethod
{
    public function __construct()
    {
        parent::__construct('append');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Memcached::append()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'Memcached::append() expects at least 2 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $key = $this->stringArg($frame->calledArgs[1], 'Memcached::append', 0, 'key');
        $value = VmMemcached::coerceValueToString($frame->calledArgs[2], 'Memcached::append', 1, 'value');
        $state = VmMemcached::state($receiver);
        $socket = VmMemcached::ensureSocket($receiver, 'Memcached::append');
        if (null === $socket) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $result = VmMemcachedNative::append($socket, $state->prefixKey.$key, $value);
        $state->resultCode = $result['code'];
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($result['ok']);
        }
    }
}

final class MemcachedPrepend extends MemcachedClassMethod
{
    public function __construct()
    {
        parent::__construct('prepend');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Memcached::prepend()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'Memcached::prepend() expects at least 2 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $key = $this->stringArg($frame->calledArgs[1], 'Memcached::prepend', 0, 'key');
        $value = VmMemcached::coerceValueToString($frame->calledArgs[2], 'Memcached::prepend', 1, 'value');
        $state = VmMemcached::state($receiver);
        $socket = VmMemcached::ensureSocket($receiver, 'Memcached::prepend');
        if (null === $socket) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $result = VmMemcachedNative::prepend($socket, $state->prefixKey.$key, $value);
        $state->resultCode = $result['code'];
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($result['ok']);
        }
    }
}

final class MemcachedIncrement extends MemcachedClassMethod
{
    public function __construct()
    {
        parent::__construct('increment');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Memcached::increment()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Memcached::increment() expects at least 1 argument, 0 given');
        }
        $key = $this->stringArg($frame->calledArgs[1], 'Memcached::increment', 0, 'key');
        $offset = \count($frame->calledArgs) >= 3
            ? $this->intArg($frame->calledArgs[2], 'Memcached::increment', 1, 'offset', 1)
            : 1;
        $state = VmMemcached::state($receiver);
        $socket = VmMemcached::ensureSocket($receiver, 'Memcached::increment');
        if (null === $socket) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $result = VmMemcachedNative::incr($socket, $state->prefixKey.$key, $offset);
        $state->resultCode = $result['code'];
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result['value']) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($result['value']);
    }
}

final class MemcachedDecrement extends MemcachedClassMethod
{
    public function __construct()
    {
        parent::__construct('decrement');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Memcached::decrement()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Memcached::decrement() expects at least 1 argument, 0 given');
        }
        $key = $this->stringArg($frame->calledArgs[1], 'Memcached::decrement', 0, 'key');
        $offset = \count($frame->calledArgs) >= 3
            ? $this->intArg($frame->calledArgs[2], 'Memcached::decrement', 1, 'offset', 1)
            : 1;
        $state = VmMemcached::state($receiver);
        $socket = VmMemcached::ensureSocket($receiver, 'Memcached::decrement');
        if (null === $socket) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $result = VmMemcachedNative::decr($socket, $state->prefixKey.$key, $offset);
        $state->resultCode = $result['code'];
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result['value']) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($result['value']);
    }
}

final class MemcachedFlush extends MemcachedClassMethod
{
    public function __construct()
    {
        parent::__construct('flush');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Memcached::flush()');
        $delay = \count($frame->calledArgs) >= 2
            ? $this->intArg($frame->calledArgs[1], 'Memcached::flush', 0, 'delay', 0)
            : 0;
        $state = VmMemcached::state($receiver);
        $socket = VmMemcached::ensureSocket($receiver, 'Memcached::flush');
        if (null === $socket) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $result = VmMemcachedNative::flush($socket, $delay);
        $state->resultCode = $result['code'];
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($result['ok']);
        }
    }
}

final class MemcachedGetMulti extends MemcachedClassMethod
{
    public function __construct()
    {
        parent::__construct('getMulti');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Memcached::getMulti()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Memcached::getMulti() expects at least 1 argument, 0 given');
        }
        $keys = VmMemcached::coerceStringListArg($frame->calledArgs[1], 'Memcached::getMulti', 0, 'keys');
        $state = VmMemcached::state($receiver);
        $socket = VmMemcached::ensureSocket($receiver, 'Memcached::getMulti');
        if (null === $socket) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $prefix = $state->prefixKey;
        $fullKeys = [];
        foreach ($keys as $k) {
            $fullKeys[] = $prefix.$k;
        }
        $result = VmMemcachedNative::getMulti($socket, $fullKeys);
        $state->resultCode = $result['code'];
        if (null === $frame->returnVar) {
            return;
        }
        if (MemcachedConstants::RES_SUCCESS !== $result['code'] && [] === $result['values']) {
            $frame->returnVar->bool(false);

            return;
        }
        $ht = new HashTable();
        $plen = \strlen($prefix);
        foreach ($result['values'] as $full => $val) {
            $userKey = ('' !== $prefix && \str_starts_with($full, $prefix))
                ? \substr($full, $plen)
                : $full;
            $slot = new Variable();
            $slot->string($val);
            $ht->add($userKey, $slot);
        }
        $frame->returnVar->array($ht);
    }
}

final class MemcachedSetMulti extends MemcachedClassMethod
{
    public function __construct()
    {
        parent::__construct('setMulti');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Memcached::setMulti()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Memcached::setMulti() expects at least 1 argument, 0 given');
        }
        $items = VmMemcached::coerceStringMapArg($frame->calledArgs[1], 'Memcached::setMulti', 0, 'items');
        $expiration = \count($frame->calledArgs) >= 3
            ? $this->intArg($frame->calledArgs[2], 'Memcached::setMulti', 1, 'expiration', 0)
            : 0;
        $state = VmMemcached::state($receiver);
        $socket = VmMemcached::ensureSocket($receiver, 'Memcached::setMulti');
        if (null === $socket) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $ok = true;
        $code = MemcachedConstants::RES_SUCCESS;
        foreach ($items as $key => $value) {
            $result = VmMemcachedNative::set($socket, $state->prefixKey.$key, $value, $expiration);
            if (!$result['ok']) {
                $ok = false;
                $code = $result['code'];
            }
        }
        $state->resultCode = $code;
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}

final class MemcachedDeleteMulti extends MemcachedClassMethod
{
    public function __construct()
    {
        parent::__construct('deleteMulti');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Memcached::deleteMulti()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Memcached::deleteMulti() expects at least 1 argument, 0 given');
        }
        $keys = VmMemcached::coerceStringListArg($frame->calledArgs[1], 'Memcached::deleteMulti', 0, 'keys');
        $state = VmMemcached::state($receiver);
        $socket = VmMemcached::ensureSocket($receiver, 'Memcached::deleteMulti');
        if (null === $socket) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $ht = new HashTable();
        foreach ($keys as $key) {
            $result = VmMemcachedNative::delete($socket, $state->prefixKey.$key);
            $slot = new Variable();
            $slot->bool($result['ok']);
            $ht->add($key, $slot);
            $state->resultCode = $result['code'];
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->array($ht);
        }
    }
}

final class MemcachedTouch extends MemcachedClassMethod
{
    public function __construct()
    {
        parent::__construct('touch');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Memcached::touch()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'Memcached::touch() expects at least 2 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $key = $this->stringArg($frame->calledArgs[1], 'Memcached::touch', 0, 'key');
        $expiration = $this->intArg($frame->calledArgs[2], 'Memcached::touch', 1, 'expiration', 0);
        $state = VmMemcached::state($receiver);
        $socket = VmMemcached::ensureSocket($receiver, 'Memcached::touch');
        if (null === $socket) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $fullKey = $state->prefixKey.$key;
        $got = VmMemcachedNative::get($socket, $fullKey);
        if (false === $got['value']) {
            $state->resultCode = $got['code'];
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $result = VmMemcachedNative::set($socket, $fullKey, $got['value'], $expiration);
        $state->resultCode = $result['code'];
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($result['ok']);
        }
    }
}

/** Memcached::cas(float|int $cas_token, string $key, mixed $value, int $expiration = 0) — #27874. */
final class MemcachedCas extends MemcachedClassMethod
{
    public function __construct()
    {
        parent::__construct('cas');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Memcached::cas()');
        if (\count($frame->calledArgs) < 4) {
            throw new \ArgumentCountError(
                'Memcached::cas() expects at least 3 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $casToken = $this->intArg($frame->calledArgs[1], 'Memcached::cas', 0, 'cas_token', 0);
        $key = $this->stringArg($frame->calledArgs[2], 'Memcached::cas', 1, 'key');
        $value = VmMemcached::coerceValueToString($frame->calledArgs[3], 'Memcached::cas', 2, 'value');
        $expiration = \count($frame->calledArgs) >= 5
            ? $this->intArg($frame->calledArgs[4], 'Memcached::cas', 3, 'expiration', 0)
            : 0;
        $state = VmMemcached::state($receiver);
        $socket = VmMemcached::ensureSocket($receiver, 'Memcached::cas');
        if (null === $socket) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $result = VmMemcachedNative::cas($socket, $casToken, $state->prefixKey.$key, $value, $expiration);
        $state->resultCode = $result['code'];
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($result['ok']);
        }
    }
}
