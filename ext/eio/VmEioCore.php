<?php

declare(strict_types=1);

namespace PHPCompiler\ext\eio;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmCallable;
use PHPCompiler\ext\standard\VmStatCache;

/**
 * Pure-PHP eio request queue — sync completion on {@see poll()} (#6442, #27837).
 *
 * No libeio / runtime/*.c. Matches PECL callback shape {@code ($data, $result[, $req])}.
 * {@see eio_read} result is the bytes string (php.net stores read bytes in $result).
 * {@see eio_stat} result is a named-key stat array (php_eio.c EIO_STAT case).
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

        if ('stat' === $type) {
            $path = (string) $p['path'];
            $raw = VmStatCache::stat($path);
            if (false === $raw) {
                $result->int(-1);

                return $result;
            }
            $result->array(self::statArrayToHashTable($raw));

            return $result;
        }

        if ('mkdir' === $type) {
            $path = (string) $p['path'];
            $mode = (int) $p['mode'];
            $ok = @mkdir($path, $mode);
            $result->int($ok ? 0 : -1);

            return $result;
        }

        if ('unlink' === $type) {
            $path = (string) $p['path'];
            $ok = @unlink($path);
            $result->int($ok ? 0 : -1);

            return $result;
        }

        if ('chmod' === $type) {
            $path = (string) $p['path'];
            $mode = (int) $p['mode'];
            $ok = @chmod($path, $mode);
            $result->int($ok ? 0 : -1);

            return $result;
        }

        if ('readdir' === $type) {
            $path = (string) $p['path'];
            $flags = (int) $p['flags'];
            $entries = @scandir($path);
            if (false === $entries) {
                $result->int(-1);

                return $result;
            }
            $names = [];
            foreach ($entries as $name) {
                if ('.' === $name || '..' === $name) {
                    continue;
                }
                $names[] = $name;
            }
            if ($flags & EioConstants::EIO_READDIR_DIRS_FIRST) {
                usort($names, static function (string $a, string $b) use ($path): int {
                    $da = is_dir($path.\DIRECTORY_SEPARATOR.$a) ? 0 : 1;
                    $db = is_dir($path.\DIRECTORY_SEPARATOR.$b) ? 0 : 1;

                    return $da <=> $db ?: strcmp($a, $b);
                });
            } elseif ($flags & EioConstants::EIO_READDIR_STAT_ORDER) {
                sort($names, \SORT_STRING);
            }
            $wantDents = (bool) ($flags & (EioConstants::EIO_READDIR_DENTS | EioConstants::EIO_READDIR_DIRS_FIRST));
            $result->array(self::readdirResultHashTable($path, $names, $wantDents));

            return $result;
        }

        $result->int(-1);

        return $result;
    }

    /**
     * @param array<int|string, int|string> $stat PHP {@see \stat()} shape
     */
    private static function statArrayToHashTable(array $stat): HashTable
    {
        $keys = ['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'rdev', 'size', 'atime', 'mtime', 'ctime', 'blksize', 'blocks'];
        $ht = new HashTable();
        foreach ($keys as $key) {
            $v = new Variable();
            if (isset($stat[$key])) {
                $v->int((int) $stat[$key]);
            } else {
                $v->int(-1);
            }
            $ht->add($key, $v);
        }

        return $ht;
    }

    /**
     * @param list<string> $names
     */
    private static function readdirResultHashTable(string $path, array $names, bool $wantDents): HashTable
    {
        $ht = new HashTable();
        $namesHt = new HashTable();
        foreach ($names as $i => $name) {
            $nv = new Variable();
            $nv->string($name);
            $namesHt->updateIndex($i, $nv);
        }
        $namesVar = new Variable();
        $namesVar->array($namesHt);
        $ht->add('names', $namesVar);
        if ($wantDents) {
            $dentsHt = new HashTable();
            foreach ($names as $i => $name) {
                $full = $path.\DIRECTORY_SEPARATOR.$name;
                $type = EioConstants::EIO_DT_UNKNOWN;
                if (is_link($full)) {
                    $type = EioConstants::EIO_DT_LNK;
                } elseif (is_dir($full)) {
                    $type = EioConstants::EIO_DT_DIR;
                } elseif (is_file($full)) {
                    $type = EioConstants::EIO_DT_REG;
                }
                $ent = new HashTable();
                $nameV = new Variable();
                $nameV->string($name);
                $ent->add('name', $nameV);
                $typeV = new Variable();
                $typeV->int($type);
                $ent->add('type', $typeV);
                $inodeV = new Variable();
                $inodeV->int(0);
                $ent->add('inode', $inodeV);
                $entVar = new Variable();
                $entVar->array($ent);
                $dentsHt->updateIndex($i, $entVar);
            }
            $dentsVar = new Variable();
            $dentsVar->array($dentsHt);
            $ht->add('dents', $dentsVar);
        }

        return $ht;
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
