<?php

declare(strict_types=1);

namespace PHPCompiler\ext\eio;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmCallable;

/**
 * Pure-PHP eio request queue — sync completion on {@see poll()} (#6442).
 *
 * No libeio / runtime/*.c. Matches PECL callback shape {@code ($data, $result[, $req])}.
 * {@see eio_read} result is the bytes string (php.net stores read bytes in $result).
 */
final class VmEioCore
{
    private static bool $initialized = false;

    /** @var array<int, string> synthetic fd → path */
    private static array $fds = [];

    private static int $nextFd = 3;

    /**
     * @var list<array{
     *     type: string,
     *     callback: Variable,
     *     data: Variable,
     *     pri: int,
     *     payload: array<string, mixed>,
     *     reqId: int,
     *     object: ObjectEntry
     * }>
     */
    private static array $queue = [];

    private static int $nextReq = 1;

    public static function init(): bool
    {
        self::$initialized = true;

        return true;
    }

    public static function nreqs(): int
    {
        return \count(self::$queue);
    }

    public static function poll(Context $ctx): int
    {
        if ([] === self::$queue) {
            return 0;
        }
        // Process one request per poll (userspace loop parity with eio_nreqs).
        $job = array_shift(self::$queue);
        $result = self::executeJob($job);
        self::invokeCallback($ctx, $job, $result);

        return 0;
    }

    /**
     * @param array{type: string, callback: Variable, data: Variable, pri: int, payload: array<string, mixed>, reqId: int, object: ObjectEntry} $job
     */
    private static function executeJob(array $job): Variable
    {
        $result = new Variable();
        $type = $job['type'];
        $p = $job['payload'];

        if ('nop' === $type) {
            $result->int(0);

            return $result;
        }

        if ('open' === $type) {
            $path = (string) $p['path'];
            $flags = (int) $p['flags'];
            $mode = 'r+b';
            if ($flags & EioConstants::EIO_O_CREAT) {
                $mode = ($flags & EioConstants::EIO_O_RDONLY) ? 'c+b' : 'c+b';
            }
            if (($flags & EioConstants::EIO_O_WRONLY) && !($flags & EioConstants::EIO_O_RDWR)) {
                $mode = ($flags & EioConstants::EIO_O_CREAT) ? 'wb' : 'cb';
            }
            if ($flags & EioConstants::EIO_O_RDWR) {
                $mode = ($flags & EioConstants::EIO_O_CREAT) ? 'c+b' : 'r+b';
            }
            if ($flags & EioConstants::EIO_O_TRUNC) {
                $mode = 'wb';
            }
            $fh = @fopen($path, $mode);
            if (false === $fh) {
                // create empty then reopen
                if ($flags & EioConstants::EIO_O_CREAT) {
                    @file_put_contents($path, '');
                    $fh = @fopen($path, 'r+b');
                }
            }
            if (false === $fh) {
                $result->int(-1);

                return $result;
            }
            fclose($fh);
            $fd = self::$nextFd++;
            self::$fds[$fd] = $path;
            $result->int($fd);

            return $result;
        }

        if ('close' === $type) {
            $fd = (int) $p['fd'];
            unset(self::$fds[$fd]);
            $result->int(0);

            return $result;
        }

        if ('read' === $type) {
            $fd = (int) $p['fd'];
            $length = (int) $p['length'];
            $offset = (int) $p['offset'];
            if (!isset(self::$fds[$fd])) {
                $result->bool(false);

                return $result;
            }
            $path = self::$fds[$fd];
            $data = @file_get_contents($path);
            if (false === $data) {
                $result->bool(false);

                return $result;
            }
            $chunk = substr($data, $offset, $length);
            $result->string(false === $chunk ? '' : $chunk);

            return $result;
        }

        if ('write' === $type) {
            $fd = (int) $p['fd'];
            $str = (string) $p['str'];
            $length = (int) $p['length'];
            $offset = (int) $p['offset'];
            if (!isset(self::$fds[$fd])) {
                $result->int(-1);

                return $result;
            }
            $path = self::$fds[$fd];
            $existing = @file_get_contents($path);
            if (false === $existing) {
                $existing = '';
            }
            $write = substr($str, 0, $length);
            if ($offset > \strlen($existing)) {
                $existing .= str_repeat("\0", $offset - \strlen($existing));
            }
            $new = substr($existing, 0, $offset).$write.substr($existing, $offset + \strlen($write));
            $ok = false !== @file_put_contents($path, $new);
            $result->int($ok ? \strlen($write) : -1);

            return $result;
        }

        $result->int(-1);

        return $result;
    }

    /**
     * @param array{type: string, callback: Variable, data: Variable, pri: int, payload: array<string, mixed>, reqId: int, object: ObjectEntry} $job
     */
    private static function invokeCallback(Context $ctx, array $job, Variable $result): void
    {
        $cb = $job['callback']->resolveIndirect();
        if (Variable::TYPE_NULL === $cb->type) {
            return;
        }
        if (!VmCallable::isCallable($ctx, $cb)) {
            return;
        }
        $reqVar = new Variable(Variable::TYPE_OBJECT);
        $reqVar->object($job['object']);
        // PECL: callback(mixed $data, mixed $result[, resource $req])
        VmCallable::invoke($ctx, $cb, $job['data'], $result, $reqVar);
    }

    public static function enqueue(
        Context $ctx,
        string $type,
        Variable $callback,
        Variable $data,
        int $pri,
        array $payload
    ): Variable {
        if (!self::$initialized) {
            self::init();
        }
        VmEioRequest::registerClass($ctx);
        $object = new ObjectEntry($ctx->classes[VmEioRequest::CLASS_LC]);
        $object->constructed = true;
        $reqId = self::$nextReq++;
        $cbPin = $callback->resolveIndirect();
        $dataPin = $data->resolveIndirect();
        // Pin copies so later frame locals cannot mutate queued slots.
        $cbCopy = new Variable();
        $cbCopy->copyFrom($cbPin);
        $dataCopy = new Variable();
        $dataCopy->copyFrom($dataPin);

        self::$queue[] = [
            'type' => $type,
            'callback' => $cbCopy,
            'data' => $dataCopy,
            'pri' => $pri,
            'payload' => $payload,
            'reqId' => $reqId,
            'object' => $object,
        ];
        usort(self::$queue, static fn (array $a, array $b): int => $b['pri'] <=> $a['pri']);

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($object);

        return $var;
    }

    public static function resolvePri(?Variable $priVar): int
    {
        if (null === $priVar || Variable::TYPE_NULL === $priVar->resolveIndirect()->type) {
            return EioConstants::EIO_PRI_DEFAULT;
        }

        return $priVar->resolveIndirect()->toInt();
    }
}
