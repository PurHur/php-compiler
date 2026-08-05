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
        VmStreamContextOptions::validateOptionsHashTable($options, 'stream_context_create');
        $out = new HashTable();
        if (null !== $options) {
            self::mergeOptions($out, $options);
        }
        self::stampMarker($out);
        if (null !== $params) {
            // parse_context_params — preserve notification Closures (#22815, re-#19696)
            self::setParams($out, $params);
        }

        return $out;
    }

    public static function mergeOptions(
        HashTable $dest,
        ?HashTable $src,
        string $functionName = 'stream_context_set_options'
    ): void {
        if (null === $src) {
            return;
        }
        VmStreamContextOptions::validateOptionsHashTable($src, $functionName);
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

    /**
     * php-src parse_context_params() (#19696) — keep notification Variable (Closures);
     * merge options into wrapper options; do not http_build_query-export callables.
     */
    public static function setParams(HashTable $dest, ?HashTable $params): void
    {
        if (null === $params) {
            return;
        }

        $notificationVar = $params->find('notification');
        if (null !== $notificationVar) {
            VmStreamNotification::validateContextNotificationParam(
                $notificationVar,
                'stream_context_set_params'
            );
            self::upsertParamSlot($dest, 'notification', $notificationVar);
        }

        $optionsVar = $params->find('options');
        if (null !== $optionsVar) {
            $resolvedOpts = $optionsVar->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $resolvedOpts->type) {
                throw new \TypeError('Invalid stream/context parameter');
            }
            self::mergeOptions($dest, $resolvedOpts->toArray(), 'stream_context_set_params');
        }

        foreach ($params->iterateKeyed(true) as [$keyVar, $valVar]) {
            $key = $keyVar->resolveIndirect();
            if (Variable::TYPE_STRING !== $key->type) {
                continue;
            }
            $name = $key->toString();
            if ('notification' === $name || 'options' === $name) {
                continue;
            }
            self::upsertParamSlot($dest, $name, $valVar);
        }
    }

    /**
     * Singular stream_context_set_option($ctx, $wrapper, $option, $value) (#3448).
     */
    public static function setSingleOption(
        HashTable $dest,
        Variable $wrapperKey,
        Variable $optionKey,
        Variable $value
    ): void {
        if (!self::hasMarker($dest)) {
            return;
        }
        $wrapperName = self::coerceOptionKeyString($wrapperKey->resolveIndirect());
        $optionName = self::coerceOptionKeyString($optionKey->resolveIndirect());
        // exportValue already collapses non-scalars to ''; avoid NestedJIT is_scalar (#27573).
        $exportedValue = self::exportValue($value);
        VmParseStr::mergeInto($dest, [
            $wrapperName => [$optionName => $exportedValue],
        ]);
    }

    public static function getParams(?HashTable $src): ?HashTable
    {
        if (null === $src || !self::hasMarker($src)) {
            return null;
        }
        $out = new HashTable();
        $options = self::getOptions($src);
        if (null !== $options) {
            $optionsVar = new Variable();
            $optionsVar->array($options);
            $out->add('options', $optionsVar);
        }
        $paramsSlot = $src->find(VmStreamContext::PARAMS_MARKER_KEY);
        if (null !== $paramsSlot) {
            $paramsHt = $paramsSlot->resolveIndirect()->toArray();
            foreach ($paramsHt->iterateKeyed(true) as [$keyVar, $valVar]) {
                $key = $keyVar->resolveIndirect();
                if (Variable::TYPE_STRING !== $key->type) {
                    continue;
                }
                $name = $key->toString();
                if ('options' === $name) {
                    continue;
                }
                $copy = new Variable();
                $copy->copyFrom($valVar);
                $out->add($name, $copy);
            }
        }

        return $out;
    }

    public static function getDefault(?HashTable $options): HashTable
    {
        $context = self::ensureDefault();
        if (null !== $options) {
            self::mergeOptions($context, $options, 'stream_context_get_default');
        }

        return $context;
    }

    public static function setDefault(HashTable $options): HashTable
    {
        $context = self::ensureDefault();
        self::mergeOptions($context, $options, 'stream_context_set_default');

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
            // NestedJIT: Variable::separateArrayForWrite is not lowered (#27573); copyFrom replaces.
            $existing->copyFrom($slot);
        } else {
            $context->add(VmStreamContext::PARAMS_MARKER_KEY, $slot);
        }
    }

    /** Copy a params key without scalar export so Closures survive (#19696). */
    private static function upsertParamSlot(HashTable $context, string $name, Variable $value): void
    {
        $paramsSlot = $context->find(VmStreamContext::PARAMS_MARKER_KEY);
        $copy = new Variable();
        $copy->copyFrom($value);

        if (null === $paramsSlot) {
            $paramsHt = new HashTable();
            $paramsHt->add($name, $copy);
            $slot = new Variable(Variable::TYPE_ARRAY);
            $slot->array($paramsHt);
            $context->add(VmStreamContext::PARAMS_MARKER_KEY, $slot);

            return;
        }

        // Rebuild params HT — NestedJIT cannot lower Variable::separateArrayForWrite (#27573).
        $oldHt = $paramsSlot->resolveIndirect()->toArray();
        $paramsHt = new HashTable();
        foreach ($oldHt->iterateKeyed(true) as [$keyVar, $valVar]) {
            $key = $keyVar->resolveIndirect();
            if (Variable::TYPE_STRING !== $key->type) {
                continue;
            }
            $keyName = $key->toString();
            if ($keyName === $name) {
                continue;
            }
            $kept = new Variable();
            $kept->copyFrom($valVar);
            $paramsHt->add($keyName, $kept);
        }
        $paramsHt->add($name, $copy);
        $slot = new Variable(Variable::TYPE_ARRAY);
        $slot->array($paramsHt);
        $paramsSlot->copyFrom($slot);
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

    private static function coerceOptionKeyString(Variable $resolved): string
    {
        if (Variable::TYPE_STRING === $resolved->type) {
            return $resolved->toString();
        }
        if (Variable::TYPE_INTEGER === $resolved->type) {
            return (string) $resolved->toInt();
        }
        if (Variable::TYPE_FLOAT === $resolved->type) {
            return (string) $resolved->toFloat();
        }
        if (Variable::TYPE_BOOLEAN === $resolved->type) {
            return $resolved->toBool() ? '1' : '';
        }
        if (Variable::TYPE_NULL === $resolved->type) {
            return '';
        }

        return '';
    }
}
