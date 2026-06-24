<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * Stream context create/merge/default for compiled JIT/AOT modules (#9340, php-in-PHP).
 *
 * HashTable-native SSOT for JIT — mirrors {@see VmStreamContext} without Variable wrappers.
 * php-src: ext/standard/streams.c — stream_context_create, stream_context_get_default
 */
final class StreamContextJitHelper
{
    private static int $nextId = 0;

    /** Process-wide default context (php-src php_stream_context_get(), #6367). */
    private static ?HashTable $defaultContext = null;

    public static function create(?HashTable $options, ?HashTable $params): HashTable
    {
        $out = new HashTable();
        if (null !== $options) {
            self::mergeOptions($out, $options);
        }
        self::stampMarker($out);
        if (null !== $params) {
            self::attachParams($out, $params);
        }

        return $out;
    }

    public static function mergeOptions(HashTable $dest, ?HashTable $src): void
    {
        if (null === $src) {
            return;
        }
        VmParseStr::mergeInto($dest, self::exportTable($src));
    }

    public static function getOptions(?HashTable $src): ?HashTable
    {
        if (null === $src || !self::hasMarker($src)) {
            return null;
        }
        $out = new HashTable();
        foreach ($src->iterateKeyed(true) as [$keyVar, $valVar]) {
            $key = $keyVar->resolveIndirect();
            if (Variable::TYPE_STRING !== $key->type) {
                continue;
            }
            $name = $key->toString();
            if (VmStreamContext::MARKER_KEY === $name || VmStreamContext::PARAMS_MARKER_KEY === $name) {
                continue;
            }
            $copy = new Variable();
            $copy->copyFrom($valVar);
            $out->add($name, $copy);
        }

        return $out;
    }

    public static function setParams(HashTable $dest, ?HashTable $params): void
    {
        if (null === $params) {
            return;
        }
        self::replaceParams($dest, $params);
    }

    public static function getDefault(?HashTable $options): HashTable
    {
        $context = self::ensureDefault();
        if (null !== $options) {
            self::mergeOptions($context, $options);
        }

        return $context;
    }

    public static function setDefault(HashTable $options): HashTable
    {
        $context = self::ensureDefault();
        self::mergeOptions($context, $options);

        return $context;
    }

    private static function ensureDefault(): HashTable
    {
        if (null === self::$defaultContext) {
            self::$defaultContext = self::create(null, null);
        }

        return self::$defaultContext;
    }

    private static function stampMarker(HashTable $ht): void
    {
        $marker = new Variable(Variable::TYPE_INTEGER);
        $marker->int(++self::$nextId);
        $ht->add(VmStreamContext::MARKER_KEY, $marker);
    }

    private static function hasMarker(HashTable $ht): bool
    {
        $marker = $ht->find(VmStreamContext::MARKER_KEY);
        if (null === $marker) {
            return false;
        }
        $resolved = $marker->resolveIndirect();

        return Variable::TYPE_INTEGER === $resolved->type;
    }

    private static function attachParams(HashTable $context, HashTable $params): void
    {
        $paramsHt = new HashTable();
        VmParseStr::mergeInto($paramsHt, self::exportTable($params));
        $slot = new Variable(Variable::TYPE_ARRAY);
        $slot->array($paramsHt);
        $context->add(VmStreamContext::PARAMS_MARKER_KEY, $slot);
    }

    private static function replaceParams(HashTable $context, HashTable $params): void
    {
        $paramsHt = new HashTable();
        VmParseStr::mergeInto($paramsHt, self::exportTable($params));
        $slot = new Variable(Variable::TYPE_ARRAY);
        $slot->array($paramsHt);
        $existing = $context->find(VmStreamContext::PARAMS_MARKER_KEY);
        if (null !== $existing) {
            $existing->separateArrayForWrite();
            $existing->copyFrom($slot);
        } else {
            $context->add(VmStreamContext::PARAMS_MARKER_KEY, $slot);
        }
    }

    /**
     * @return array<int|string, mixed>
     */
    private static function exportTable(HashTable $ht): array
    {
        $out = [];
        foreach ($ht->iterateKeyed(true) as [$keyVar, $valVar]) {
            $key = $keyVar->resolveIndirect();
            if (Variable::TYPE_STRING === $key->type) {
                $out[$key->toString()] = self::exportValue($valVar);
            } elseif (Variable::TYPE_INTEGER === $key->type) {
                $out[$key->toInt()] = self::exportValue($valVar);
            }
        }

        return $out;
    }

    private static function exportValue(Variable $v): mixed
    {
        $v = $v->resolveIndirect();
        switch ($v->type) {
            case Variable::TYPE_NULL:
                return null;
            case Variable::TYPE_INTEGER:
                return $v->toInt();
            case Variable::TYPE_FLOAT:
                return $v->toFloat();
            case Variable::TYPE_BOOLEAN:
                return $v->toBool();
            case Variable::TYPE_STRING:
                return $v->toString();
            case Variable::TYPE_ARRAY:
                return self::exportTable($v->toArray());
            default:
                return '';
        }
    }
}
