<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

use PHPCompiler\Frame;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPCompiler\Web\Superglobals;

/**
 * filter batch API runtime helpers for JIT/AOT (#3294).
 *
 * php-src: ext/filter/filter.c — php_filter_has_var, php_filter_input_array, php_filter_var_array
 */
final class FilterBatchJitHelper
{
    public static function hasVar(int $type, string $key): Variable
    {
        $ctx = self::requireActiveContext();
        $out = new Variable();
        $out->bool(VmFilter::hasInputVar($ctx, $type, $key));

        return $out;
    }

    public static function filterVarArray(HashTable $data, HashTable $definition, int $addEmpty): Variable
    {
        $frame = new Frame();
        $result = VmFilter::filterVarArray($data, $definition, $addEmpty, $frame);
        $out = new Variable();
        if (null === $result) {
            $out->bool(false);

            return $out;
        }
        $out->array($result);

        return $out;
    }

    /** Int filter-ID overload — apply one FILTER_* to every element (#21937). */
    public static function filterVarArrayByFilterId(HashTable $data, int $filterId, int $addEmpty): Variable
    {
        $frame = new Frame();
        $result = VmFilter::filterVarArray($data, $filterId, $addEmpty, $frame);
        $out = new Variable();
        if (null === $result) {
            $out->bool(false);

            return $out;
        }
        $out->array($result);

        return $out;
    }

    public static function filterInputArray(int $type, HashTable $definition, int $addEmpty): Variable
    {
        $ctx = self::requireActiveContext();
        $frame = new Frame();
        $frame->vmContext = $ctx;
        $result = VmFilter::filterInputArray($ctx, $type, $definition, $addEmpty, $frame);
        $out = new Variable();
        if (null === $result) {
            $out->null();

            return $out;
        }
        $out->array($result);

        return $out;
    }

    /** Int filter-ID overload for filter_input_array() (#21937). */
    public static function filterInputArrayByFilterId(int $type, int $filterId, int $addEmpty): Variable
    {
        $ctx = self::requireActiveContext();
        $frame = new Frame();
        $frame->vmContext = $ctx;
        $result = VmFilter::filterInputArray($ctx, $type, $filterId, $addEmpty, $frame);
        $out = new Variable();
        if (null === $result) {
            $out->null();

            return $out;
        }
        $out->array($result);

        return $out;
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
