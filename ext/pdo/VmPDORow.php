<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pdo;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * PDORow — FETCH_LAZY row object (php-src ext/pdo/pdo_stmt.c pdo_row_ce; #22294).
 *
 * Empty public method table; column values are instance properties (+ queryString).
 */
final class VmPDORow
{
    public const CLASS_LC = 'pdorow';

    private static ?ClassEntry $classEntry = null;

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            self::$classEntry = $ctx->classes[self::CLASS_LC];

            return;
        }

        $entry = new ClassEntry('PDORow');
        $entry->isInternal = true;
        $entry->isFinal = true;
        $priv = CfgFunc::FLAG_PRIVATE;
        $ctor = new PDORowConstruct();
        $entry->constructor = $ctor;
        $entry->methods['__construct'] = $ctor;
        $entry->methodVisibility['__construct'] = $priv;

        self::$classEntry = $entry;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    /**
     * Build a PDORow snapshot for the current fetched assoc row (#22294).
     *
     * @param array<string|int, mixed> $row
     */
    public static function fromRow(Context $ctx, PdoStatementState $st, array $row): ObjectEntry
    {
        if (null === self::$classEntry) {
            self::registerClass($ctx);
        }
        if (null === self::$classEntry) {
            throw new \LogicException('PDORow class not registered');
        }
        $object = new ObjectEntry(self::$classEntry);
        $object->constructed = true;
        $qs = $object->allocateProperty('queryString');
        $qs->string($st->sql);
        foreach ($row as $key => $value) {
            if (\is_int($key)) {
                // Prefer named columns; numeric BOTH duplicates stay off the object
                // when the same value is also under a string name (FETCH_ASSOC shape).
                continue;
            }
            $slot = $object->allocateProperty((string) $key);
            VmPDO::assignScalar($slot, $value);
        }

        return $object;
    }
}

/** Reject user `new PDORow()` — php-src pdo_row_new / create_object (#22294). */
final class PDORowConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        throw new \PDOException('You may not create a PDORow manually');
    }
}
