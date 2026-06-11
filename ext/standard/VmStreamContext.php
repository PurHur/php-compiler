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
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $resolved->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($options) must be of type array, %s given',
                $functionName,
                $argNum,
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
}
