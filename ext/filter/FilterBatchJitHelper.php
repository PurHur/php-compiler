<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

use PHPCompiler\Frame;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPCompiler\Web\Superglobals;

/**
 * filter batch API runtime helpers for JIT/AOT (#3294, #34574, #34580).
 *
 * filter_var_array* returns {@see HashTable} (ArrayChunk `__hashtable__*` ABI). Variable
 * returns mis-marshaled as empty VmIni under thin AOT (#34574). Filtering avoids
 * {@see VmFilter} — NestedJIT of that call graph SIGSEGVs under standalone AOT
 * (peer #34572). VM {@see filter_var_array::execute()} still uses VmFilter.
 * Thin AOT user scripts use {@see \PHPCompiler\JIT\Builtin\FilterVarArrayLlvm}.
 *
 * filter_input_array* returns {@see HashTable}|null (ArrayChunk `__hashtable__*` ABI).
 * Variable returns abort under thin AOT (#34580; peer #34574).
 *
 * php-src: ext/filter/filter.c — php_filter_var_array / php_filter_array_handler
 */
final class FilterBatchJitHelper
{
    private const FILTER_VALIDATE_INT = 0x0101;

    private const FILTER_VALIDATE_BOOLEAN = 0x0102;

    private const FILTER_VALIDATE_FLOAT = 0x0103;

    private const FILTER_DEFAULT = 0x0204;

    public static function hasVar(int $type, string $key): Variable
    {
        $ctx = self::requireActiveContext();
        $out = new Variable();
        $out->bool(VmFilter::hasInputVar($ctx, $type, $key));

        return $out;
    }

    public static function filterVarArray(HashTable $data, HashTable $definition, int $addEmpty): HashTable
    {
        $out = new HashTable();
        foreach ($definition->iterateKeyed(true) as [$defKeyVar, $filterVar]) {
            $defKey = $defKeyVar->resolveIndirect();
            $filterId = self::FILTER_DEFAULT;
            $filterResolved = $filterVar->resolveIndirect();
            if (Variable::TYPE_INTEGER === $filterResolved->type) {
                $filterId = $filterResolved->toInt();
            } elseif (Variable::TYPE_ARRAY === $filterResolved->type) {
                $opt = $filterResolved->toArray()->find('filter');
                if (null !== $opt && !$opt->isUndefined()) {
                    $optR = $opt->resolveIndirect();
                    if (Variable::TYPE_INTEGER === $optR->type) {
                        $filterId = $optR->toInt();
                    }
                }
            }
            $stored = self::lookupDataValue($data, $defKey);
            if (null === $stored) {
                if (0 !== $addEmpty) {
                    $false = new Variable();
                    $false->bool(false);
                    self::storeEntry($out, $defKeyVar, $false);
                }
                continue;
            }
            self::storeEntry($out, $defKeyVar, self::applyFilter($stored->resolveIndirect(), $filterId));
        }

        return $out;
    }

    /** Int filter-ID overload — apply one FILTER_* to every element (#21937). */
    public static function filterVarArrayByFilterId(HashTable $data, int $filterId, int $addEmpty): HashTable
    {
        $out = new HashTable();
        foreach ($data->iterateKeyed(true) as [$keyVar, $valueVar]) {
            self::storeEntry($out, $keyVar, self::applyFilter($valueVar->resolveIndirect(), $filterId));
        }

        return $out;
    }

    /**
     * @return HashTable|null null when INPUT_* snapshot missing (CLI → Zend NULL).
     *
     * Snapshot miss returns null without NestedJIT of filterVarArray (#34580).
     * Present table filters via {@see VmFilter::filterVarArray} (?HashTable, not Variable).
     */
    public static function filterInputArray(int $type, HashTable $definition, int $addEmpty): ?HashTable
    {
        $ctx = self::requireActiveContext();
        $src = VmFilter::requestInputTable($ctx, $type);
        if (null === $src) {
            return null;
        }
        $frame = new Frame();
        $frame->vmContext = $ctx;

        return VmFilter::filterVarArray($src, $definition, $addEmpty, $frame);
    }

    /** Int filter-ID overload for filter_input_array() (#21937, #34580). */
    public static function filterInputArrayByFilterId(int $type, int $filterId, int $addEmpty): ?HashTable
    {
        $ctx = self::requireActiveContext();
        $src = VmFilter::requestInputTable($ctx, $type);
        if (null === $src) {
            return null;
        }
        $frame = new Frame();
        $frame->vmContext = $ctx;

        return VmFilter::filterVarArray($src, $filterId, $addEmpty, $frame);
    }

    private static function applyFilter(Variable $value, int $filterId): Variable
    {
        $out = new Variable();
        if (self::FILTER_VALIDATE_INT === $filterId) {
            $parsed = self::parseInt($value);
            if (null === $parsed) {
                $out->bool(false);
            } else {
                $out->int($parsed);
            }

            return $out;
        }
        if (self::FILTER_VALIDATE_BOOLEAN === $filterId) {
            $out->bool(self::parseBool($value));

            return $out;
        }
        if (self::FILTER_VALIDATE_FLOAT === $filterId) {
            $parsed = self::parseFloat($value);
            if (null === $parsed) {
                $out->bool(false);
            } else {
                $out->float($parsed);
            }

            return $out;
        }
        if (self::FILTER_DEFAULT === $filterId) {
            $out->string($value->toString());

            return $out;
        }
        $out->bool(false);

        return $out;
    }

    private static function parseInt(Variable $value): ?int
    {
        if (Variable::TYPE_INTEGER === $value->type) {
            return $value->toInt();
        }
        $s = $value->toString();
        $start = 0;
        $len = strlen($s);
        while ($start < $len && ' ' === $s[$start]) {
            ++$start;
        }
        $end = $len;
        while ($end > $start && ' ' === $s[$end - 1]) {
            --$end;
        }
        if ($start >= $end) {
            return null;
        }
        $i = $start;
        $neg = false;
        if ('+' === $s[$i] || '-' === $s[$i]) {
            $neg = '-' === $s[$i];
            ++$i;
        }
        if ($i >= $end) {
            return null;
        }
        $num = 0;
        for ($j = $i; $j < $end; ++$j) {
            $c = $s[$j];
            if ($c < '0' || $c > '9') {
                return null;
            }
            $num = $num * 10 + (ord($c) - 48);
        }

        return $neg ? -$num : $num;
    }

    private static function parseBool(Variable $value): bool
    {
        if (Variable::TYPE_BOOLEAN === $value->type) {
            return $value->toBool();
        }
        $s = $value->toString();
        // lowercase compare without strtolower (NestedJIT AOT link surface).
        return '1' === $s || 'true' === $s || 'TRUE' === $s || 'on' === $s || 'ON' === $s
            || 'yes' === $s || 'YES' === $s;
    }

    private static function parseFloat(Variable $value): ?float
    {
        if (Variable::TYPE_FLOAT === $value->type) {
            return $value->toFloat();
        }
        if (Variable::TYPE_INTEGER === $value->type) {
            return (float) $value->toInt();
        }
        $s = $value->toString();
        $start = 0;
        $len = strlen($s);
        while ($start < $len && ' ' === $s[$start]) {
            ++$start;
        }
        $end = $len;
        while ($end > $start && ' ' === $s[$end - 1]) {
            --$end;
        }
        if ($start >= $end) {
            return null;
        }
        $slice = '';
        for ($i = $start; $i < $end; ++$i) {
            $slice .= $s[$i];
        }
        if (!is_numeric($slice)) {
            return null;
        }

        return (float) $slice;
    }

    private static function lookupDataValue(HashTable $data, Variable $key): ?Variable
    {
        if (Variable::TYPE_STRING === $key->type) {
            return $data->find($key->toString());
        }
        if (Variable::TYPE_INTEGER === $key->type) {
            return $data->findIndex($key->toInt());
        }

        return null;
    }

    private static function storeEntry(HashTable $out, Variable $keyVar, Variable $filtered): void
    {
        $key = $keyVar->resolveIndirect();
        if (Variable::TYPE_STRING === $key->type) {
            $out->add($key->toString(), $filtered);
        } elseif (Variable::TYPE_INTEGER === $key->type) {
            $out->addIndex($key->toInt(), $filtered);
        }
    }

    private static function requireActiveContext(): \PHPCompiler\VM\Context
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException('filter batch JIT helper requires active VM context (#3294)');
        }

        return $ctx;
    }
}
