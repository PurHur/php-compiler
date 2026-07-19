<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dba;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Dba\Connection opaque object (php-src ext/dba/dba.stub.php; PHP 8.4+; #4422).
 *
 * @phpstan-type DbaState array{
 *   path: string,
 *   mode: string,
 *   handler: string,
 *   writable: bool,
 *   closed: bool,
 *   fp: resource|null,
 *   cursor: int
 * }
 */
final class VmDbaConnection
{
    public const CLASS_LC = 'dba\\connection';

    public const CLASS_NAME = 'Dba\\Connection';

    /** @var array<int, DbaState> */
    private static array $state = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }
        $entry = new ClassEntry(self::CLASS_NAME);
        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    /**
     * @param resource $fp
     */
    public static function wrap(
        string $path,
        string $mode,
        string $handler,
        bool $writable,
        $fp,
        Context $ctx
    ): Variable {
        self::registerClass($ctx);
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $object->constructed = true;
        self::$state[$object->id] = [
            'path' => $path,
            'mode' => $mode,
            'handler' => $handler,
            'writable' => $writable,
            'closed' => false,
            'fp' => $fp,
            'cursor' => 0,
        ];
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($object);

        return $var;
    }

    public static function isLive(ObjectEntry $object): bool
    {
        return isset(self::$state[$object->id]) && !self::$state[$object->id]['closed'];
    }

    /**
     * @return DbaState
     */
    public static function state(ObjectEntry $object): array
    {
        if (!self::isLive($object)) {
            throw new \TypeError('supplied resource is not a valid DBA connection resource');
        }

        return self::$state[$object->id];
    }

    /**
     * @param callable(DbaState): void $mutator
     */
    public static function mutate(ObjectEntry $object, callable $mutator): void
    {
        $row = self::state($object);
        $mutator($row);
        self::$state[$object->id] = $row;
    }

    public static function close(ObjectEntry $object): void
    {
        if (!isset(self::$state[$object->id]) || self::$state[$object->id]['closed']) {
            return;
        }
        $fp = self::$state[$object->id]['fp'];
        if (\is_resource($fp)) {
            \fclose($fp);
        }
        self::$state[$object->id]['fp'] = null;
        self::$state[$object->id]['closed'] = true;
    }

    /**
     * Live connection object ids → path (for dba_list).
     *
     * @return array<int, string>
     */
    public static function listPaths(): array
    {
        $out = [];
        foreach (self::$state as $id => $row) {
            if ($row['closed']) {
                continue;
            }
            $out[$id] = $row['path'];
        }

        return $out;
    }
}
