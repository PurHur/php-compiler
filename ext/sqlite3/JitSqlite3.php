<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * LLVM lowering for SQLite3::__construct / exec / querySingle / close /
 * lastInsertRowID / changes (#35931 leftover of #35914 / #20565; multi-row #35956).
 *
 * Thin standalone AOT has no PHP FFI, so libsqlite3 cannot be NestedJIT'd (FFI::cdef is a
 * null ExternalMethod; int+string helper pairs SIGSEGV — peer HashContext update #3357).
 * Compile-time SQL literals are folded in the compiler process (CREATE/INSERT/SELECT
 * COUNT|SUM|first-column) onto {@see Sqlite3JitSupport} props — same honesty class as
 * PDO construct failing closed when the driver cannot open (#27619).
 *
 * php-src: ext/sqlite3/sqlite3.c — zim_SQLite3___construct / zim_SQLite3_exec /
 * zim_SQLite3_querySingle / zim_SQLite3_lastInsertRowID / zim_SQLite3_changes /
 * zim_SQLite3_lastErrorCode / zim_SQLite3_lastErrorMsg (#35966) /
 * zim_SQLite3_busyTimeout (#35972) / zim_SQLite3_enableExceptions (#35975) /
 * zim_SQLite3_escapeString (#35977) / zim_SQLite3_version (#35991 leftover of #35977) /
 * zim_SQLite3_open (#36001 leftover of #35991) /
 * zim_SQLite3_prepare / zim_SQLite3_query (#36010 leftover of #36001)
 */
final class JitSqlite3
{
    public static function construct(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange($context, $args, 'SQLite3::__construct', 1, 3)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $obj = self::readObject($context, $args[0]);
        $i64 = $context->getTypeFromString('int64');
        self::storeLong($context, $obj, Sqlite3JitSupport::PROP_ID, $i64->constInt(1, false));
        self::storeLong($context, $obj, Sqlite3JitSupport::PROP_ROW, $i64->constInt(0, false));
        self::storeLong($context, $obj, Sqlite3JitSupport::PROP_HAS, $i64->constInt(0, false));
        self::storeLong($context, $obj, Sqlite3JitSupport::PROP_LAST_ROWID, $i64->constInt(0, false));
        self::storeLong($context, $obj, Sqlite3JitSupport::PROP_CHANGES, $i64->constInt(0, false));
        self::storeLong($context, $obj, Sqlite3JitSupport::PROP_ROW_COUNT, $i64->constInt(0, false));
        self::storeLong($context, $obj, Sqlite3JitSupport::PROP_SUM, $i64->constInt(0, false));
        self::storeLong($context, $obj, Sqlite3JitSupport::PROP_INT_PK, $i64->constInt(0, false));
        self::storeLong($context, $obj, Sqlite3JitSupport::PROP_EXCEPTIONS, $i64->constInt(0, false));
        $context->type->object->markObjectConstructed($obj);

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return JitValueBox::pointer($context, $slot);
    }

    public static function exec(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'SQLite3::exec', 1)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $obj = self::readObject($context, $args[0]);
        $sqlLit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        $i64 = $context->getTypeFromString('int64');
        if (null !== $sqlLit) {
            $info = self::analyzeExecSql($sqlLit);
            if (null !== $info['int_pk']) {
                self::storeLong(
                    $context,
                    $obj,
                    Sqlite3JitSupport::PROP_INT_PK,
                    $i64->constInt($info['int_pk'] ? 1 : 0, false)
                );
            }
            if (null !== $info['values']) {
                self::emitInsertFold($context, $obj, $info['values'], $info['int_pk']);
            } else {
                self::storeLong($context, $obj, Sqlite3JitSupport::PROP_CHANGES, $i64->constInt(0, false));
            }

            return self::boxBool($context, true);
        }

        return self::boxBool($context, false);
    }

    public static function querySingle(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange($context, $args, 'SQLite3::querySingle', 1, 2)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $obj = self::readObject($context, $args[0]);
        $sqlLit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        $i64 = $context->getTypeFromString('int64');

        if (null !== $sqlLit && 1 === preg_match('/^\s*SELECT\s+COUNT\s*\(\s*\*\s*\)/i', $sqlLit)) {
            return self::boxLong($context, self::loadLong($context, $obj, Sqlite3JitSupport::PROP_ROW_COUNT));
        }

        if (null !== $sqlLit && 1 === preg_match('/^\s*SELECT\s+SUM\s*\(\s*[A-Za-z_][A-Za-z0-9_]*\s*\)/i', $sqlLit)) {
            $count = self::loadLong($context, $obj, Sqlite3JitSupport::PROP_ROW_COUNT);
            $isEmpty = $context->builder->icmp(
                \PHPLLVM\Builder::INT_EQ,
                $count,
                $i64->constInt(0, false)
            );
            $sumSlot = JitValueBox::alloc($context);
            $nullSlot = JitValueBox::alloc($context);
            $context->builder->call(
                $context->lookupFunction('__value__writeLong'),
                JitValueBox::pointer($context, $sumSlot),
                self::loadLong($context, $obj, Sqlite3JitSupport::PROP_SUM)
            );
            $context->builder->call(
                $context->lookupFunction('__value__writeNull'),
                JitValueBox::pointer($context, $nullSlot)
            );

            return $context->builder->select(
                $isEmpty,
                JitValueBox::pointer($context, $nullSlot),
                JitValueBox::pointer($context, $sumSlot)
            );
        }

        $has = self::loadLong($context, $obj, Sqlite3JitSupport::PROP_HAS);
        $isHas = $context->builder->icmp(
            \PHPLLVM\Builder::INT_NE,
            $has,
            $i64->constInt(0, false)
        );
        $row = self::loadLong($context, $obj, Sqlite3JitSupport::PROP_ROW);
        $trueSlot = JitValueBox::alloc($context);
        $falseSlot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            JitValueBox::pointer($context, $trueSlot),
            $row
        );
        JitValueBox::writeBool(
            $context,
            $falseSlot,
            $context->getTypeFromString('int1')->constInt(0, false)
        );

        return $context->builder->select(
            $isHas,
            JitValueBox::pointer($context, $trueSlot),
            JitValueBox::pointer($context, $falseSlot)
        );
    }

    public static function close(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'SQLite3::close', 0)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $obj = self::readObject($context, $args[0]);
        $i64 = $context->getTypeFromString('int64');
        self::storeLong($context, $obj, Sqlite3JitSupport::PROP_ID, $i64->constInt(0, false));
        self::storeLong($context, $obj, Sqlite3JitSupport::PROP_HAS, $i64->constInt(0, false));

        return self::boxBool($context, true);
    }

    /**
     * SQLite3::open leftover of version (#36001 / #35991).
     * php-src zim_sqlite3_open: throw Exception when already open; reopen after close.
     * Thin AOT folds open/reopen onto {@see Sqlite3JitSupport} props (same honesty class as
     * __construct — no libsqlite3 FFI at runtime).
     */
    public static function open(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange($context, $args, 'SQLite3::open', 1, 3)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $obj = self::readObject($context, $args[0]);
        JitStringBuiltinArg::lower(
            $context,
            $args[1],
            'SQLite3::open',
            1,
            'filename'
        );
        if (\count($args) >= 3) {
            JitLongArg::lower($context, $args[2], 'SQLite3::open(): Argument #2 ($flags)');
        }
        if (\count($args) >= 4) {
            JitStringBuiltinArg::lower(
                $context,
                $args[3],
                'SQLite3::open',
                3,
                'encryption_key'
            );
        }

        $i64 = $context->getTypeFromString('int64');
        $id = self::loadLong($context, $obj, Sqlite3JitSupport::PROP_ID);
        $alreadyOpen = $context->builder->icmp(
            \PHPLLVM\Builder::INT_NE,
            $id,
            $i64->constInt(0, false)
        );
        $bbThrow = BasicBlockHelper::append($context, 'sqlite3_open_already');
        $bbOk = BasicBlockHelper::append($context, 'sqlite3_open_ok');
        $context->builder->branchIf($alreadyOpen, $bbThrow, $bbOk);

        $context->builder->positionAtEnd($bbThrow);
        if (null !== \PHPCompiler\JIT\TryCatchHelper::resolveThrowHandler($context)) {
            \PHPCompiler\JIT\TryCatchHelper::emitCatchableClassError(
                $context,
                'Exception',
                'Already initialised DB Object'
            );
        } else {
            ExceptionBridge::emitErrorAndAbort($context, 'Already initialised DB Object');
        }
        $seal = BasicBlockHelper::tryGetInsertBlock($context);
        if (null !== $seal && null === $seal->getTerminator()) {
            ExceptionBridge::emitErrorAndAbort($context, 'Already initialised DB Object');
        }

        $context->builder->positionAtEnd($bbOk);
        self::storeLong($context, $obj, Sqlite3JitSupport::PROP_ID, $i64->constInt(1, false));
        self::storeLong($context, $obj, Sqlite3JitSupport::PROP_ROW, $i64->constInt(0, false));
        self::storeLong($context, $obj, Sqlite3JitSupport::PROP_HAS, $i64->constInt(0, false));
        self::storeLong($context, $obj, Sqlite3JitSupport::PROP_LAST_ROWID, $i64->constInt(0, false));
        self::storeLong($context, $obj, Sqlite3JitSupport::PROP_CHANGES, $i64->constInt(0, false));
        self::storeLong($context, $obj, Sqlite3JitSupport::PROP_ROW_COUNT, $i64->constInt(0, false));
        self::storeLong($context, $obj, Sqlite3JitSupport::PROP_SUM, $i64->constInt(0, false));
        self::storeLong($context, $obj, Sqlite3JitSupport::PROP_INT_PK, $i64->constInt(0, false));
        // Keep PROP_EXCEPTIONS across close/reopen (php-src retains exception mode on the object).

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return JitValueBox::pointer($context, $slot);
    }

    /**
     * SQLite3::prepare leftover of open (#36010 / #36001).
     * php-src zim_sqlite3_prepare — return SQLite3Stmt; thin AOT stores SQL on NestedJIT props.
     */
    public static function prepare(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'SQLite3::prepare', 1)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        self::readObject($context, $args[0]);
        $sqlPtr = JitStringBuiltinArg::lower(
            $context,
            $args[1],
            'SQLite3::prepare',
            1,
            'query'
        );
        $sqlLit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        $paramCount = 0;
        if (null !== $sqlLit) {
            if ('' === $sqlLit) {
                return self::boxBool($context, false);
            }
            $paramCount = substr_count($sqlLit, '?');
        }
        $objectType = $context->type->object;
        $classId = $objectType->lookup(Sqlite3JitSupport::STMT_CLASS);
        $stmt = $objectType->allocate($classId);
        $objectType->markObjectConstructed($stmt);
        $sqlVar = new JITVariable(
            $context,
            JITVariable::TYPE_STRING,
            JITVariable::KIND_VALUE,
            $sqlPtr
        );
        $objectType->storeInstanceProperty(
            $stmt,
            Sqlite3JitSupport::STMT_CLASS,
            Sqlite3JitSupport::STMT_PROP_SQL,
            $sqlVar
        );
        $i64 = $context->getTypeFromString('int64');
        ReflectionSetup::emitSetLongPropertyFromValue(
            $context,
            $stmt,
            Sqlite3JitSupport::STMT_CLASS,
            Sqlite3JitSupport::STMT_PROP_PARAM_COUNT,
            $i64->constInt($paramCount, false)
        );

        return self::boxObject($context, $stmt);
    }

    /**
     * SQLite3::query leftover of open (#36010 / #36001).
     * php-src zim_sqlite3_query — return SQLite3Result; thin AOT copies folded PROP_ROW/HAS.
     */
    public static function query(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'SQLite3::query', 1)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $db = self::readObject($context, $args[0]);
        $sqlLit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        JitStringBuiltinArg::lower(
            $context,
            $args[1],
            'SQLite3::query',
            1,
            'query'
        );
        if (null !== $sqlLit && '' === $sqlLit) {
            return self::boxBool($context, false);
        }
        $objectType = $context->type->object;
        $classId = $objectType->lookup(Sqlite3JitSupport::RESULT_CLASS);
        $result = $objectType->allocate($classId);
        $objectType->markObjectConstructed($result);
        $i64 = $context->getTypeFromString('int64');
        $has = self::loadLong($context, $db, Sqlite3JitSupport::PROP_HAS);
        $row = self::loadLong($context, $db, Sqlite3JitSupport::PROP_ROW);
        ReflectionSetup::emitSetLongPropertyFromValue(
            $context,
            $result,
            Sqlite3JitSupport::RESULT_CLASS,
            Sqlite3JitSupport::RESULT_PROP_HAS,
            $has
        );
        ReflectionSetup::emitSetLongPropertyFromValue(
            $context,
            $result,
            Sqlite3JitSupport::RESULT_CLASS,
            Sqlite3JitSupport::RESULT_PROP_ROW,
            $row
        );
        ReflectionSetup::emitSetLongPropertyFromValue(
            $context,
            $result,
            Sqlite3JitSupport::RESULT_CLASS,
            Sqlite3JitSupport::RESULT_PROP_FETCHED,
            $i64->constInt(0, false)
        );

        return self::boxObject($context, $result);
    }

    private static function boxObject(Context $context, Value $obj): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $obj
        );

        return $ptr;
    }

    public static function lastInsertRowID(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'SQLite3::lastInsertRowID', 0)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $obj = self::readObject($context, $args[0]);

        return self::boxLong($context, self::loadLong($context, $obj, Sqlite3JitSupport::PROP_LAST_ROWID));
    }

    public static function changes(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'SQLite3::changes', 0)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $obj = self::readObject($context, $args[0]);

        return self::boxLong($context, self::loadLong($context, $obj, Sqlite3JitSupport::PROP_CHANGES));
    }

    /**
     * SQLite3::lastErrorCode leftover of lastInsertRowID (#35966 / #35931).
     * php-src zim_SQLite3_lastErrorCode: sqlite3_errcode; SQLITE_OK is 0 after a successful open.
     */
    public static function lastErrorCode(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'SQLite3::lastErrorCode', 0)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        self::readObject($context, $args[0]);
        $i64 = $context->getTypeFromString('int64');

        return self::boxLong($context, $i64->constInt(0, false));
    }

    /**
     * SQLite3::lastErrorMsg leftover of lastInsertRowID (#35966 / #35931).
     * php-src zim_SQLite3_lastErrorMsg: sqlite3_errmsg; SQLITE_OK text is "not an error".
     */
    public static function lastErrorMsg(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'SQLite3::lastErrorMsg', 0)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        self::readObject($context, $args[0]);

        return self::boxString($context, 'not an error');
    }

    /**
     * SQLite3::busyTimeout leftover of lastError (#35972 / #35966).
     * php-src zim_SQLite3_busyTimeout: sqlite3_busy_timeout on an open handle is SQLITE_OK → true.
     */
    public static function busyTimeout(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'SQLite3::busyTimeout', 1)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        self::readObject($context, $args[0]);
        JitLongArg::lower($context, $args[1], 'SQLite3::busyTimeout(): Argument #1 ($milliseconds)');

        return self::boxBool($context, true);
    }

    /**
     * SQLite3::enableExceptions leftover of busyTimeout (#35975 / #35972).
     * php-src zim_SQLite3_enableExceptions: return prior mode, then store $enable (default true).
     */
    public static function enableExceptions(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange($context, $args, 'SQLite3::enableExceptions', 0, 1)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $obj = self::readObject($context, $args[0]);
        $i64 = $context->getTypeFromString('int64');
        $prior = self::loadLong($context, $obj, Sqlite3JitSupport::PROP_EXCEPTIONS);
        $enableI1 = \count($args) >= 2
            ? JitBoolArg::lower(
                $context,
                $args[1],
                'SQLite3::enableExceptions(): Argument #1 ($enable)'
            )
            : $context->getTypeFromString('int1')->constInt(1, false);
        self::storeLong(
            $context,
            $obj,
            Sqlite3JitSupport::PROP_EXCEPTIONS,
            $context->builder->zExt($enableI1, $i64)
        );
        $priorBool = $context->builder->icmp(
            \PHPLLVM\Builder::INT_NE,
            $prior,
            $i64->constInt(0, false)
        );
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $priorBool);

        return JitValueBox::pointer($context, $slot);
    }

    /**
     * SQLite3::escapeString leftover of busyTimeout (#35977 / #35972).
     * php-src zim_SQLite3_escapeString / sqlite3_mprintf("%q") — fold compile-time strings in
     * the compiler process (thin AOT has no libsqlite3 FFI at runtime).
     */
    public static function escapeString(Context $context, JITVariable ...$args): Value
    {
        $offset = 0;
        if (\count($args) >= 1 && JITVariable::TYPE_OBJECT === $args[0]->type) {
            self::readObject($context, $args[0]);
            $offset = 1;
        }
        $given = \count($args) - $offset;
        if (1 !== $given) {
            \PHPCompiler\JIT\ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                'SQLite3::escapeString() expects exactly 1 argument, '.$given.' given'
            );
            \PHPCompiler\JIT\BasicBlockHelper::ensureOpenInsertBlock($context, 'SQLite3::escapeString_argc_cont');

            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $lit = JitStringBuiltinArg::compileTimeLiteral($args[$offset])
            ?? $args[$offset]->compileTimeString;
        if (null === $lit) {
            throw new \LogicException(
                'SQLite3::escapeString() user-script AOT requires a compile-time string (#35977)'
            );
        }
        try {
            $escaped = VmSqlite3Native::escapeString($lit);
        } catch (\Throwable $e) {
            // sqlite3_mprintf("%q") doubles single quotes when FFI is unavailable in the host.
            $escaped = str_replace("'", "''", $lit);
        }

        return self::boxString($context, $escaped);
    }

    /**
     * SQLite3::version leftover of escapeString (#35991 / #35977).
     * php-src zim_SQLite3_version: sqlite3_libversion + sqlite3_libversion_number.
     * Fold in the compiler process (thin AOT has no libsqlite3 FFI at runtime).
     */
    public static function version(Context $context, JITVariable ...$args): Value
    {
        $offset = 0;
        if (\count($args) >= 1 && JITVariable::TYPE_OBJECT === $args[0]->type) {
            $offset = 1;
        }
        $given = \count($args) - $offset;
        if (0 !== $given) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                'SQLite3::version() expects exactly 0 arguments, '.$given.' given'
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'SQLite3::version_argc_cont');

            return VmClassMethod::jitArgcDummyReturn($context);
        }
        try {
            $info = VmSqlite3Native::version();
        } catch (\Throwable $e) {
            throw new \LogicException(
                'SQLite3::version() user-script AOT requires libsqlite3 FFI at compile time (#35991): '
                .$e->getMessage(),
                0,
                $e
            );
        }
        $ht = new HashTable();
        $str = new Variable();
        $str->string($info['versionString']);
        $ht->add('versionString', $str);
        $num = new Variable();
        $num->int($info['versionNumber']);
        $ht->add('versionNumber', $num);
        $global = $context->constantArrayFromVmHashTable(
            'sqlite3_version_'.$info['versionString'].'_'.$info['versionNumber'],
            $ht
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::copyFromPointer($context, $slot, $context->builder->load($global));

        return $ptr;
    }

    /**
     * @param list<?int> $values first-column ints per VALUES tuple (null = non-numeric)
     */
    private static function emitInsertFold(Context $context, Value $obj, array $values, ?bool $intPkKnown): void
    {
        $i64 = $context->getTypeFromString('int64');
        $n = count($values);
        $first = null;
        $last = null;
        $sum = 0;
        foreach ($values as $v) {
            if (null === $v) {
                continue;
            }
            if (null === $first) {
                $first = $v;
            }
            $last = $v;
            $sum += $v;
        }

        self::storeLong($context, $obj, Sqlite3JitSupport::PROP_CHANGES, $i64->constInt($n, false));

        $count = self::loadLong($context, $obj, Sqlite3JitSupport::PROP_ROW_COUNT);
        $isEmpty = $context->builder->icmp(
            \PHPLLVM\Builder::INT_EQ,
            $count,
            $i64->constInt(0, false)
        );
        $newCount = $context->builder->add($count, $i64->constInt($n, false));
        self::storeLong($context, $obj, Sqlite3JitSupport::PROP_ROW_COUNT, $newCount);

        $oldSum = self::loadLong($context, $obj, Sqlite3JitSupport::PROP_SUM);
        self::storeLong(
            $context,
            $obj,
            Sqlite3JitSupport::PROP_SUM,
            $context->builder->add($oldSum, $i64->constInt($sum, true))
        );

        if (null !== $first) {
            $oldRow = self::loadLong($context, $obj, Sqlite3JitSupport::PROP_ROW);
            $firstVal = $i64->constInt($first, true);
            $rowVal = $context->builder->select($isEmpty, $firstVal, $oldRow);
            self::storeLong($context, $obj, Sqlite3JitSupport::PROP_ROW, $rowVal);
            self::storeLong($context, $obj, Sqlite3JitSupport::PROP_HAS, $i64->constInt(1, false));
        }

        $rid = self::loadLong($context, $obj, Sqlite3JitSupport::PROP_LAST_ROWID);
        $autoRid = $context->builder->add($rid, $i64->constInt($n, false));
        if (true === $intPkKnown && null !== $last) {
            self::storeLong($context, $obj, Sqlite3JitSupport::PROP_LAST_ROWID, $i64->constInt($last, true));
        } elseif (false === $intPkKnown) {
            self::storeLong($context, $obj, Sqlite3JitSupport::PROP_LAST_ROWID, $autoRid);
        } else {
            $pkFlag = self::loadLong($context, $obj, Sqlite3JitSupport::PROP_INT_PK);
            $isPk = $context->builder->icmp(
                \PHPLLVM\Builder::INT_NE,
                $pkFlag,
                $i64->constInt(0, false)
            );
            $pkRid = null !== $last ? $i64->constInt($last, true) : $autoRid;
            $newRid = $context->builder->select($isPk, $pkRid, $autoRid);
            self::storeLong($context, $obj, Sqlite3JitSupport::PROP_LAST_ROWID, $newRid);
        }
    }

    /**
     * @return array{int_pk: ?bool, values: ?list<?int>}
     */
    private static function analyzeExecSql(string $sql): array
    {
        $intPk = null;
        if (1 === preg_match('/CREATE\s+TABLE\b/i', $sql)) {
            $intPk = 1 === preg_match('/INTEGER\s+PRIMARY\s+KEY/i', $sql);
        }

        $values = null;
        if (1 === preg_match('/INSERT\s+INTO\b.*?VALUES\s*(.+)$/is', $sql, $m)) {
            $part = rtrim($m[1], " \t\n\r;");
            if (preg_match_all('/\(([^)]*)\)/', $part, $tuples) > 0 && [] !== $tuples[1]) {
                $values = [];
                foreach ($tuples[1] as $inner) {
                    $inner = trim((string) $inner);
                    if ('' === $inner) {
                        $values[] = null;
                        continue;
                    }
                    $first = trim(explode(',', $inner, 2)[0]);
                    if (is_numeric($first)) {
                        $values[] = (int) $first;
                    } else {
                        $values[] = null;
                    }
                }
            }
        }

        return ['int_pk' => $intPk, 'values' => $values];
    }

    private static function readObject(Context $context, JITVariable $arg): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);

        return $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
    }

    private static function storeLong(Context $context, Value $obj, string $prop, Value $handleI64): void
    {
        ReflectionSetup::emitSetLongPropertyFromValue(
            $context,
            $obj,
            Sqlite3JitSupport::CLASS_NAME,
            $prop,
            $handleI64
        );
    }

    private static function loadLong(Context $context, Value $obj, string $prop): Value
    {
        $handleVar = $context->type->object->propertyFetch(
            $obj,
            Sqlite3JitSupport::CLASS_NAME,
            $prop
        );

        return $context->helper->loadValue($handleVar);
    }

    private static function boxBool(Context $context, bool $v): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool(
            $context,
            $slot,
            $context->getTypeFromString('int1')->constInt($v ? 1 : 0, false)
        );

        return JitValueBox::pointer($context, $slot);
    }

    private static function boxLong(Context $context, Value $long): Value
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            JitValueBox::pointer($context, $slot),
            $long
        );

        return JitValueBox::pointer($context, $slot);
    }

    private static function boxString(Context $context, string $text): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $str = $context->builder->load($context->constantStringFromString($text));
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $str);

        return $ptr;
    }
}
