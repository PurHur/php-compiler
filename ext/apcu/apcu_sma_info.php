<?php

declare(strict_types=1);

namespace PHPCompiler\ext\apcu;

use PHPCompiler\Frame;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/** apcu_sma_info() — PECL apcu synthetic SMA info (#22253). */
final class apcu_sma_info extends ApcuFunction
{
    public function __construct()
    {
        parent::__construct('apcu_sma_info');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(
                'apcu_sma_info() expects at most 1 argument, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $limited = false;
        if (isset($frame->calledArgs[0])) {
            $limited = $frame->calledArgs[0]->resolveIndirect()->toBool();
        }

        $frame->returnVar->copyFrom(self::importSmaInfo(VmApcu::smaInfo($limited)));
    }

    /**
     * @param array<string, mixed> $info
     */
    private static function importSmaInfo(array $info): Variable
    {
        $ht = new HashTable();
        foreach ($info as $key => $value) {
            $slot = new Variable();
            if (\is_int($value)) {
                $slot->int($value);
            } elseif (\is_float($value)) {
                $slot->float($value);
            } elseif (\is_array($value)) {
                $slot->copyFrom(self::importNestedArray($value));
            } else {
                $slot->null();
            }
            $ht->add((string) $key, $slot);
        }
        $var = new Variable();
        $var->array($ht);

        return $var;
    }

    /**
     * @param array<mixed> $rows
     */
    private static function importNestedArray(array $rows): Variable
    {
        $outer = new HashTable();
        $i = 0;
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $inner = new HashTable();
            $j = 0;
            $assoc = false;
            foreach ($row as $rk => $rv) {
                if (!\is_int($rk)) {
                    $assoc = true;
                }
                $cell = new Variable();
                if (\is_int($rv)) {
                    $cell->int($rv);
                } elseif (\is_array($rv)) {
                    $cell->copyFrom(self::importNestedArray($rv));
                } else {
                    $cell->string((string) $rv);
                }
                if ($assoc) {
                    $inner->add((string) $rk, $cell);
                } else {
                    $inner->addIndex($j, $cell);
                    ++$j;
                }
            }
            $rowVar = new Variable();
            $rowVar->array($inner);
            $outer->addIndex($i, $rowVar);
            ++$i;
        }
        $var = new Variable();
        $var->array($outer);

        return $var;
    }
}
