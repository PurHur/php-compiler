<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmString;

/**
 * SQLite3 VM class (php-src ext/sqlite3/sqlite3.c; issue #3434).
 */
final class VmSQLite3
{
    public const CLASS_LC = 'sqlite3';

    /** @var array<int, Sqlite3State> */
    private static array $store = [];

    /** @var array<int, ObjectEntry> */
    private static array $objects = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC]) && isset($ctx->classes[self::CLASS_LC]->methods['openblob'])) {
            return;
        }

        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('SQLite3');
        $entry->isInternal = true;
        // Declared casing is the storage key (ClassConstName / #25929). Do not
        // lowercase — SQLite3::OK / defined('SQLite3::OK') must resolve (#28098).
        foreach (Sqlite3Constants::CLASS_CONSTANTS as $name => $value) {
            $const = new Variable(Variable::TYPE_INTEGER);
            $const->int($value);
            $canonical = Sqlite3Constants::CLASS_CONSTANT_NAMES[$name];
            $entry->constants[$canonical] = $const;
            $entry->constNames[$canonical] = $canonical;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry->constructor = new SQLite3Construct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;

        $methods = [
            'close' => new SQLite3Close(),
            'open' => new SQLite3Open(),
            'exec' => new SQLite3Exec(),
            'querysingle' => new SQLite3QuerySingle(),
            'query' => new SQLite3Query(),
            'prepare' => new SQLite3Prepare(),
            'changes' => new SQLite3Changes(),
            'lastinsertrowid' => new SQLite3LastInsertRowID(),
            'lasterrorcode' => new SQLite3LastErrorCode(),
            'lasterrormsg' => new SQLite3LastErrorMsg(),
            'escapestring' => new SQLite3EscapeString(),
            'busytimeout' => new SQLite3BusyTimeout(),
            'enableexceptions' => new SQLite3EnableExceptions(),
            'createfunction' => new SQLite3CreateFunction(),
            'createaggregate' => new SQLite3CreateAggregate(),
            'createcollation' => new SQLite3CreateCollation(),
            'setauthorizer' => new SQLite3SetAuthorizer(),
            'loadextension' => new SQLite3LoadExtension(),
            'backup' => new SQLite3Backup(),
            'openblob' => new SQLite3OpenBlob(),
            'version' => new SQLite3Version(),
        ];
        foreach ($methods as $name => $method) {
            $entry->methods[$name] = $method;
            $entry->methodVisibility[$name] = $pub;
            $entry->methodNames[$name] = self::methodDisplayName($name);
        }
        $entry->methodVisibility['escapestring'] = CfgFunc::FLAG_STATIC | $pub;
        $entry->methodVisibility['version'] = CfgFunc::FLAG_STATIC | $pub;

        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function initObject(ObjectEntry $entry, string $filename, int $flags): void
    {
        if (!VmSqlite3Native::available()) {
            throw new \LogicException('SQLite3 requires libsqlite3 FFI in this compiler build');
        }

        $state = new Sqlite3State();
        $state->filename = $filename;
        $state->db = VmSqlite3Native::open($filename, $flags);
        self::$store[$entry->id] = $state;
        self::$objects[$entry->id] = $entry;
        $entry->constructed = true;
    }

    /**
     * SQLite3::open() — reopen after close, or reject when already open (php-src; #20565).
     */
    public static function openObject(ObjectEntry $entry, string $filename, int $flags): void
    {
        if (!VmSqlite3Native::available()) {
            throw new \LogicException('SQLite3 requires libsqlite3 FFI in this compiler build');
        }
        if ($entry->constructed) {
            $state = self::state($entry);
            if (!$state->closed && null !== $state->db) {
                throw new \Exception('Already initialised DB Object');
            }
            $state->filename = $filename;
            $state->db = VmSqlite3Native::open($filename, $flags);
            $state->closed = false;
            $state->functions = [];
            $state->collations = [];
            $state->aggregates = [];

            return;
        }
        self::initObject($entry, $filename, $flags);
    }

    /** php-src lastErrorCode — 0 when closed / not initialised. */
    public static function lastErrorCode(ObjectEntry $entry): int
    {
        $state = self::state($entry);
        if ($state->closed || null === $state->db) {
            return 0;
        }

        return VmSqlite3Native::errcode($state->db);
    }

    /** php-src lastErrorMsg — '' when closed / not initialised. */
    public static function lastErrorMsg(ObjectEntry $entry): string
    {
        $state = self::state($entry);
        if ($state->closed || null === $state->db) {
            return '';
        }

        return VmSqlite3Native::errmsg($state->db);
    }

    public static function publicTypeLabel(Variable $var): string
    {
        return self::typeLabel($var);
    }

    public static function objectById(int $id): ObjectEntry
    {
        if (!isset(self::$objects[$id])) {
            throw new \LogicException('SQLite3 object missing for result/stmt handle');
        }

        return self::$objects[$id];
    }

    public static function requireReceiver(Variable $var, string $label): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf('%s must be called on SQLite3, %s given', $label, self::typeLabel($var)));
        }
        $object = $var->toObject();
        if (self::CLASS_LC !== strtolower($object->class->name)) {
            throw new \TypeError(\sprintf('%s must be called on SQLite3, %s given', $label, $object->class->name));
        }
        if (!$object->constructed) {
            throw new \TypeError(\sprintf('%s must be called on SQLite3, uninitialized %s given', $label, $object->class->name));
        }

        return $object;
    }

    public static function state(ObjectEntry $entry): Sqlite3State
    {
        $state = self::$store[$entry->id] ?? null;
        if (null === $state) {
            throw new \LogicException('SQLite3 internal state missing in this compiler build');
        }

        return $state;
    }

    public static function requireOpenDb(ObjectEntry $entry, string $label): \FFI\CData
    {
        $state = self::state($entry);
        if ($state->closed || null === $state->db) {
            throw new \LogicException(\sprintf('%s(): The SQLite3 object has not been properly initialized', $label));
        }

        return $state->db;
    }

    public static function coerceStringArg(Variable $var, string $label, int $index, string $paramName): string
    {
        return VmString::coerceStringBuiltinArg($var, $label, $index, $paramName);
    }

    public static function coerceIntArg(Variable $var, string $label, int $index, string $paramName, int $default = 0): int
    {
        if (Variable::TYPE_NULL === $var->resolveIndirect()->type) {
            return $default;
        }
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_INTEGER === $resolved->type) {
            return $resolved->toInt();
        }
        if (Variable::TYPE_FLOAT === $resolved->type) {
            return (int) $resolved->toFloat();
        }
        if (Variable::TYPE_BOOLEAN === $resolved->type) {
            return $resolved->toBool() ? 1 : 0;
        }
        if (Variable::TYPE_STRING === $resolved->type) {
            $raw = $resolved->toString();
            if ('' === $raw) {
                return 0;
            }
            if (is_numeric($raw)) {
                return (int) $raw;
            }
        }

        throw new \TypeError(\sprintf('%s(): Argument #%d ($%s) must be of type int, %s given', $label, $index + 1, $paramName, self::typeLabel($var)));
    }

    public static function coerceBoolArg(Variable $var, string $label, int $index, string $paramName, bool $default = false): bool
    {
        if (Variable::TYPE_NULL === $var->resolveIndirect()->type) {
            return $default;
        }
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_BOOLEAN === $resolved->type) {
            return $resolved->toBool();
        }
        if (Variable::TYPE_INTEGER === $resolved->type) {
            return 0 !== $resolved->toInt();
        }

        throw new \TypeError(\sprintf('%s(): Argument #%d ($%s) must be of type bool, %s given', $label, $index + 1, $paramName, self::typeLabel($var)));
    }

    public static function assignReturnValue(Variable $returnVar, array|string|int|float|null|false $value): void
    {
        if (false === $value) {
            $returnVar->bool(false);

            return;
        }
        if (null === $value) {
            $returnVar->null();

            return;
        }
        if (\is_int($value)) {
            $returnVar->int($value);

            return;
        }
        if (\is_float($value)) {
            $returnVar->float($value);

            return;
        }
        if (\is_string($value)) {
            $returnVar->string($value);

            return;
        }
        $ht = new \PHPCompiler\VM\HashTable();
        foreach ($value as $key => $item) {
            $slot = new Variable();
            self::assignReturnValue($slot, $item);
            $ht->add((string) $key, $slot);
        }
        $returnVar->array($ht);
    }

    private static function methodDisplayName(string $lc): string
    {
        return match ($lc) {
            'querysingle' => 'querySingle',
            'lastinsertrowid' => 'lastInsertRowID',
            'lasterrorcode' => 'lastErrorCode',
            'lasterrormsg' => 'lastErrorMsg',
            'escapestring' => 'escapeString',
            'busytimeout' => 'busyTimeout',
            'enableexceptions' => 'enableExceptions',
            'createfunction' => 'createFunction',
            'createaggregate' => 'createAggregate',
            'createcollation' => 'createCollation',
            'loadextension' => 'loadExtension',
            'openblob' => 'openBlob',
            default => $lc,
        };
    }

    /**
     * Expand registered scalar UDFs + evaluate aggregates in SQL (#19862 / #20585).
     */
    public static function expandSql(ObjectEntry $entry, string $sql): string
    {
        $state = self::state($entry);
        if (!VmSqlite3Authorizer::allow($state, $sql)) {
            throw new \SQLite3Exception('not authorized');
        }
        if ([] !== $state->functions) {
            $sql = VmSqlite3Udf::expandSql($sql, $state->functions);
        }
        if ([] !== $state->aggregates && null !== $state->db && !$state->closed) {
            $sql = VmSqlite3Udf::expandAggregates($state->db, $sql, $state->aggregates);
        }
        if ([] !== $state->collations && null !== $state->db && !$state->closed) {
            $sql = VmSqlite3Udf::expandCollations($state->db, $sql, $state->collations);
        }

        return $sql;
    }

    /**
     * Map SQLite3Exception through exception-mode policy (php-src sqlite3_report_error).
     * Returns true when the error was swallowed (caller should return false).
     */
    public static function handleException(ObjectEntry $entry, \SQLite3Exception $e): bool
    {
        if (self::state($entry)->exceptions) {
            throw $e;
        }

        return true;
    }

    private static function typeLabel(Variable $var): string
    {
        $resolved = $var->resolveIndirect();

        return match ($resolved->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => $resolved->toObject()->class->name,
            Variable::TYPE_RESOURCE => 'resource',
            default => 'mixed',
        };
    }
}
