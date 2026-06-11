<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * VM stream-context handles as arrays (issue #1377).
 *
 * fopen() and file_get_contents() can resolve these via {@see self::toHostResource()}.
 */
final class VmStreamContext
{
    public const MARKER_KEY = '__phpc_stream_context';

    /** Params bag (distinct from wrapper options; php-src php_stream_context.params). */
    public const PARAMS_MARKER_KEY = '__phpc_stream_context_params';

    /** @var array<int, resource> */
    private static array $resources = [];

    private static int $nextId = 0;

    /**
     * Build a VM stream-context array from caller options (issue #1377, #2457, #6815).
     *
     * Deep-copies nested option arrays like JIT {@see \PHPCompiler\JIT\Builtin\StreamContextRuntime}
     * so assigning the result does not share buckets with the input variable.
     */
    public static function createFromVmOptions(?Variable $optionsVar, ?Variable $paramsVar = null): HashTable
    {
        $hostOptions = [];
        if (null !== $optionsVar) {
            $resolved = $optionsVar->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $resolved->type) {
                throw new \LogicException(
                    'stream_context_create() argument #1 must be an array in this compiler build'
                );
            }
            $exported = VmHttpBuildQuery::export($resolved);
            if (!\is_array($exported)) {
                throw new \LogicException(
                    'stream_context_create() argument #1 must be an array in this compiler build'
                );
            }
            $hostOptions = $exported;
        }

        $hostParams = [];
        if (null !== $paramsVar) {
            $resolvedParams = $paramsVar->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $resolvedParams->type) {
                throw new \LogicException(
                    'stream_context_create() argument #2 must be an array in this compiler build'
                );
            }
            $exportedParams = VmHttpBuildQuery::export($resolvedParams);
            if (!\is_array($exportedParams)) {
                throw new \LogicException(
                    'stream_context_create() argument #2 must be an array in this compiler build'
                );
            }
            $hostParams = $exportedParams;
        }

        $resource = \stream_context_create($hostOptions, $hostParams);
        $id = ++self::$nextId;
        self::$resources[$id] = $resource;

        $ht = null !== $optionsVar && Variable::TYPE_ARRAY === $optionsVar->resolveIndirect()->type
            ? $optionsVar->resolveIndirect()->toArray()->duplicate()
            : new HashTable();
        $marker = new Variable(Variable::TYPE_INTEGER);
        $marker->int($id);
        $ht->add(self::MARKER_KEY, $marker);
        if ([] !== $hostParams) {
            self::attachParamsHashTable($ht, $hostParams);
        }

        return $ht;
    }

    /**
     * @param array<string, mixed>  $options
     * @param array<string, mixed>|null $params
     */
    public static function create(array $options = [], ?array $params = null): HashTable
    {
        $resource = \stream_context_create($options, $params ?? []);
        $id = ++self::$nextId;
        self::$resources[$id] = $resource;

        $ht = new HashTable();
        VmParseStr::mergeInto($ht, $options);
        $marker = new Variable(Variable::TYPE_INTEGER);
        $marker->int($id);
        $ht->add(self::MARKER_KEY, $marker);
        if (null !== $params && [] !== $params) {
            self::attachParamsHashTable($ht, $params);
        }

        return $ht;
    }

    public static function isRepresentation(Variable $var): bool
    {
        return null !== self::idFrom($var);
    }

    public static function idFrom(Variable $var): ?int
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $resolved->type) {
            return null;
        }
        $marker = $resolved->toArray()->find(self::MARKER_KEY);
        if (null === $marker) {
            return null;
        }
        $idVar = $marker->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $idVar->type) {
            return null;
        }

        return $idVar->toInt();
    }

    public static function toHostResource(Variable $var): mixed
    {
        $id = self::idFrom($var);
        if (null === $id) {
            return null;
        }

        return self::$resources[$id] ?? null;
    }

    /**
     * php-src ext/standard/streams.c — stream_context_set_options/get_options context arg.
     */
    public static function requireRepresentation(
        Variable $var,
        string $functionName,
        int $argNum = 1
    ): Variable {
        $resolved = $var->resolveIndirect();
        if (!self::isRepresentation($resolved)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($context) must be of type resource, %s given',
                $functionName,
                $argNum,
                VmStreamArg::debugTypeName($resolved)
            ));
        }

        return $resolved;
    }

    public static function requireOptionsArray(
        Variable $var,
        string $functionName,
        int $argNum = 2
    ): Variable {
        return self::requireContextArrayArg($var, $functionName, $argNum, 'options');
    }

    public static function requireParamsArray(
        Variable $var,
        string $functionName,
        int $argNum = 2
    ): Variable {
        return self::requireContextArrayArg($var, $functionName, $argNum, 'params');
    }

    private static function requireContextArrayArg(
        Variable $var,
        string $functionName,
        int $argNum,
        string $label
    ): Variable {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $resolved->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type array, %s given',
                $functionName,
                $argNum,
                $label,
                VmStreamArg::debugTypeName($resolved)
            ));
        }

        return $resolved;
    }

    /**
     * Merge options into an existing stream context (issue #6517).
     */
    public static function setOptions(Variable $context, Variable $options): bool
    {
        $context->separateArrayForWrite();
        $context = self::requireRepresentation($context, 'stream_context_set_options');
        $options = self::requireOptionsArray($options, 'stream_context_set_options');

        $exported = VmHttpBuildQuery::export($options);
        if (!\is_array($exported)) {
            throw new \TypeError(
                'stream_context_set_options(): Argument #2 ($options) must be of type array, '
                .VmStreamArg::debugTypeName($options).' given'
            );
        }

        VmParseStr::mergeInto($context->toArray(), $exported);

        return true;
    }

    /**
     * Replace params on an existing stream context (issue #6122, php-src stream_context_set_params).
     */
    public static function setParams(Variable $context, Variable $params): bool
    {
        $context->separateArrayForWrite();
        $context = self::requireRepresentation($context, 'stream_context_set_params');
        $params = self::requireParamsArray($params, 'stream_context_set_params');

        $exported = VmHttpBuildQuery::export($params);
        if (!\is_array($exported)) {
            throw new \TypeError(
                'stream_context_set_params(): Argument #2 ($params) must be of type array, '
                .VmStreamArg::debugTypeName($params).' given'
            );
        }

        self::replaceParamsHashTable($context, $exported);

        $resource = self::toHostResource($context);
        if (\is_resource($resource) && \function_exists('stream_context_set_params')) {
            \stream_context_set_params($resource, $exported);
        }

        return true;
    }

    /**
     * Return stream wrapper options without the internal marker key (issue #6517).
     */
    public static function getOptionsHashTable(Variable $context): HashTable
    {
        $context = self::requireRepresentation($context, 'stream_context_get_options');
        $source = $context->toArray();
        $out = new HashTable();
        foreach ($source->iterateKeyed(true) as [$key, $value]) {
            $k = $key->resolveIndirect();
            if (Variable::TYPE_STRING === $k->type && self::MARKER_KEY === $k->toString()) {
                continue;
            }
            if (Variable::TYPE_STRING === $k->type && self::PARAMS_MARKER_KEY === $k->toString()) {
                continue;
            }
            $copyVal = new Variable();
            $copyVal->copyFrom($value);
            if (Variable::TYPE_STRING === $k->type) {
                $out->add($k->toString(), $copyVal);
            } elseif (Variable::TYPE_INTEGER === $k->type) {
                $out->addIndex($k->toInt(), $copyVal);
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function attachParamsHashTable(HashTable $context, array $params): void
    {
        $paramsHt = new HashTable();
        VmParseStr::mergeInto($paramsHt, $params);
        $slot = new Variable();
        $slot->array($paramsHt);
        $context->add(self::PARAMS_MARKER_KEY, $slot);
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function replaceParamsHashTable(Variable $context, array $params): void
    {
        $paramsHt = new HashTable();
        VmParseStr::mergeInto($paramsHt, $params);
        $slot = new Variable();
        $slot->array($paramsHt);
        $contextArray = $context->toArray();
        $existing = $contextArray->find(self::PARAMS_MARKER_KEY);
        if (null !== $existing) {
            $existing->separateArrayForWrite();
            $existing->copyFrom($slot);
        } else {
            $contextArray->add(self::PARAMS_MARKER_KEY, $slot);
        }
    }
}
