<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/**
 * NestedJIT SQLite3Result::fetchArray (#36010 leftover of #36001).
 * php-src: ext/sqlite3/sqlite3.c zim_SQLite3Result_fetchArray
 */
final class JitSqlite3Result
{
    /** @var list<array{assoc: array<string, mixed>, num: array<int, mixed>}> */
    private static array $lastQueryRows = [];

    /**
     * @param list<array{assoc: array<string, mixed>, num: array<int, mixed>}> $rows
     */
    public static function registerFoldedRows(array $rows): string
    {
        self::$lastQueryRows = $rows;

        return '__phpc_sq3res_last';
    }

    public static function attachRowToken(Value $resultObj, string $rowToken): int
    {
        return 1;
    }

    public static function fetchArray(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange($context, $args, 'SQLite3Result::fetchArray', 0, 1)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $obj = self::readObject($context, $args[0]);
        $mode = Sqlite3Constants::BOTH;
        if (\count($args) >= 2) {
            $modeLit = $args[1]->compileTimeLong ?? null;
            if (null !== $modeLit) {
                $mode = (int) $modeLit;
            } else {
                JitLongArg::lower($context, $args[1], 'SQLite3Result::fetchArray(): Argument #1 ($mode)');
            }
        }
        $i64 = $context->getTypeFromString('int64');
        $resultSlot = JitValueBox::alloc($context);
        $resultPtr = JitValueBox::pointer($context, $resultSlot);

        $cursor = self::loadLong($context, $obj, Sqlite3JitSupport::RESULT_PROP_CURSOR);
        $rowCount = self::loadLong($context, $obj, Sqlite3JitSupport::RESULT_PROP_ROW_COUNT);

        $bbDone = BasicBlockHelper::append($context, 'sqlite3_fetch_done');
        $bbFalse = BasicBlockHelper::append($context, 'sqlite3_fetch_false');
        $bbRow = BasicBlockHelper::append($context, 'sqlite3_fetch_row');

        $atEnd = $context->builder->icmp(
            \PHPLLVM\Builder::INT_SGE,
            $cursor,
            $rowCount
        );
        $context->builder->branchIf($atEnd, $bbFalse, $bbRow);

        $context->builder->positionAtEnd($bbRow);
        $nextCursor = $context->builder->add($cursor, $i64->constInt(1, false));
        ReflectionSetup::emitSetLongPropertyFromValue(
            $context,
            $obj,
            Sqlite3JitSupport::RESULT_CLASS,
            Sqlite3JitSupport::RESULT_PROP_CURSOR,
            $nextCursor
        );
        self::emitMultiRowFetch($context, $resultPtr, self::$lastQueryRows, $cursor, $mode, $i64);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbFalse);
        JitValueBox::writeBool(
            $context,
            $resultSlot,
            $context->getTypeFromString('int1')->constInt(0, false)
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);

        return $resultPtr;
    }

    /**
     * @param list<array{assoc: array<string, mixed>, num: array<int, mixed>}> $rows
     */
    private static function emitMultiRowFetch(
        Context $context,
        Value $resultPtr,
        array $rows,
        Value $cursor,
        int $mode,
        \PHPLLVM\Type $i64
    ): void {
        $n = count($rows);
        if (0 === $n) {
            return;
        }
        $htSlots = [];
        for ($i = 0; $i < $n; ++$i) {
            $htSlots[$i] = self::buildRowHashtable($context, $rows[$i], $mode, $i64);
        }
        $selected = $htSlots[0];
        for ($i = 1; $i < $n; ++$i) {
            $isIdx = $context->builder->icmp(
                \PHPLLVM\Builder::INT_EQ,
                $cursor,
                $i64->constInt($i, false)
            );
            $selected = $context->builder->select($isIdx, $htSlots[$i], $selected);
        }
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $resultPtr,
            $selected
        );
    }

    /**
     * @param array{assoc: array<string, mixed>, num: array<int, mixed>} $row
     */
    private static function buildRowHashtable(Context $context, array $row, int $mode, \PHPLLVM\Type $i64): Value
    {
        $ht = HashTableHelper::alloc($context);
        $useAssoc = ($mode & Sqlite3Constants::ASSOC) !== 0;
        $useNum = ($mode & Sqlite3Constants::NUM) !== 0;
        if ($useNum) {
            foreach ($row['num'] as $idx => $val) {
                if (\is_string($val)) {
                    $str = $context->builder->load($context->constantStringFromString($val));
                    $context->builder->call(
                        $context->lookupFunction('__hashtable__setStringAt'),
                        $ht,
                        $i64->constInt((int) $idx, false),
                        $str
                    );
                } elseif (\is_int($val)) {
                    $context->builder->call(
                        $context->lookupFunction('__hashtable__setLongAt'),
                        $ht,
                        $i64->constInt((int) $idx, false),
                        $i64->constInt($val, true)
                    );
                }
            }
        }
        if ($useAssoc) {
            foreach ($row['assoc'] as $name => $val) {
                $key = $context->builder->load($context->constantStringFromString((string) $name));
                if (\is_string($val)) {
                    $str = $context->builder->load($context->constantStringFromString($val));
                    $context->builder->call(
                        $context->lookupFunction('__hashtable__setStringKeyString'),
                        $ht,
                        $key,
                        $str
                    );
                } elseif (\is_int($val)) {
                    $context->builder->call(
                        $context->lookupFunction('__hashtable__setStringKeyLong'),
                        $ht,
                        $key,
                        $i64->constInt($val, true)
                    );
                }
            }
        }

        return $ht;
    }

    private static function readObject(Context $context, JITVariable $arg): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);

        return $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
    }

    private static function loadLong(Context $context, Value $obj, string $prop): Value
    {
        $handleVar = $context->type->object->propertyFetch(
            $obj,
            Sqlite3JitSupport::RESULT_CLASS,
            $prop
        );

        return $context->helper->loadValue($handleVar);
    }
}
