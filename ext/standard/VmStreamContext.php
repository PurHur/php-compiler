<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * VM stream-context handles as arrays (issue #1377, #8131).
 *
 * Pure VM HashTable + marker id — mirrors JIT {@see \PHPCompiler\JIT\Builtin\StreamContextRuntime}
 * without host Zend stream_context_create() delegation (bootstrap/M5).
 */
final class VmStreamContext
{
    public const MARKER_KEY = '__phpc_stream_context';

    /** Params bag (distinct from wrapper options; php-src php_stream_context.params). */
    public const PARAMS_MARKER_KEY = '__phpc_stream_context_params';

    private static int $nextId = 0;

    /** Process-wide default context (php-src php_stream_context_get(), #6367). */
    private static ?Variable $defaultContext = null;

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
            if (Variable::TYPE_NULL === $resolved->type) {
                // php-src: null options → default empty context (ext/standard/streams.c, #13356)
            } elseif (Variable::TYPE_ARRAY !== $resolved->type) {
                throw new \LogicException(
                    'stream_context_create() argument #1 must be an array in this compiler build'
                );
            } else {
                $exported = VmHttpBuildQuery::export($resolved);
                if (!\is_array($exported)) {
                    throw new \LogicException(
                        'stream_context_create() argument #1 must be an array in this compiler build'
                    );
                }
                $hostOptions = $exported;
            }
        }

        $hostParams = [];
        if (null !== $paramsVar) {
            $resolvedParams = $paramsVar->resolveIndirect();
            if (Variable::TYPE_NULL === $resolvedParams->type) {
                // php-src: null params → omitted (#13356)
            } elseif (Variable::TYPE_ARRAY !== $resolvedParams->type) {
                throw new \LogicException(
                    'stream_context_create() argument #2 must be an array in this compiler build'
                );
            } else {
                $exportedParams = VmHttpBuildQuery::export($resolvedParams);
                if (!\is_array($exportedParams)) {
                    throw new \LogicException(
                        'stream_context_create() argument #2 must be an array in this compiler build'
                    );
                }
                $hostParams = $exportedParams;
            }
        }

        $id = ++self::$nextId;

        $optionsType = null !== $optionsVar ? $optionsVar->resolveIndirect()->type : Variable::TYPE_NULL;
        $ht = Variable::TYPE_ARRAY === $optionsType
            ? $optionsVar->resolveIndirect()->toArray()->duplicate()
            : new HashTable();
        $marker = new Variable(Variable::TYPE_INTEGER);
        $marker->int($id);
        $ht->add(self::MARKER_KEY, $marker);
        if ([] !== $hostParams) {
            self::attachParamsHashTable($ht, $hostParams);
        }
        $ht->markResourceLikeHandle();

        return $ht;
    }

    /**
     * @param array<string, mixed>  $options
     * @param array<string, mixed>|null $params
     */
    public static function create(array $options = [], ?array $params = null): HashTable
    {
        $id = ++self::$nextId;

        $ht = new HashTable();
        VmParseStr::mergeInto($ht, $options);
        $marker = new Variable(Variable::TYPE_INTEGER);
        $marker->int($id);
        $ht->add(self::MARKER_KEY, $marker);
        if (null !== $params && [] !== $params) {
            self::attachParamsHashTable($ht, $params);
        }
        $ht->markResourceLikeHandle();

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

    /** Optional stream-context parameter on copy/rename/unlink (ext/standard/file.c). */
    public static function validateOptionalContextArg(
        Variable $var,
        string $functionName,
        int $argNum
    ): void {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            return;
        }
        if (!self::isRepresentation($resolved)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($context) must be of type resource or null, %s given',
                $functionName,
                $argNum,
                VmStreamArg::debugTypeName($resolved)
            ));
        }
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
     * stream_context_get_default() — lazy singleton with optional merge (ext/standard/streams.c, #6367).
     */
    public static function getDefault(?Variable $optionsVar = null): Variable
    {
        $context = self::ensureDefaultContext();
        if (null !== $optionsVar) {
            self::setOptions(
                $context,
                self::requireOptionsArray($optionsVar, 'stream_context_get_default', 1)
            );
        }

        return $context;
    }

    /**
     * stream_context_set_default() — merge options into process default (#6367, pairs #3448).
     */
    public static function setDefault(Variable $optionsVar): Variable
    {
        $context = self::ensureDefaultContext();
        self::setOptions(
            $context,
            self::requireOptionsArray($optionsVar, 'stream_context_set_default', 1)
        );

        return $context;
    }

    private static function ensureDefaultContext(): Variable
    {
        if (null === self::$defaultContext) {
            self::$defaultContext = new Variable();
            self::$defaultContext->array(self::create());
        }

        return self::$defaultContext;
    }

    /**
     * Merge options into an existing stream context (issue #6517).
     */
    public static function setOptions(Variable $context, Variable $options): bool
    {
        $context = self::requireRepresentation($context, 'stream_context_set_options');
        $context->separateArrayForWrite();
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
     * Replace params on an existing stream context (issue #6122, #8058).
     *
     * VM HashTable only — no host Zend params sync (bootstrap/M5).
     */
    public static function setParams(Variable $context, Variable $params): bool
    {
        $context = self::requireRepresentation($context, 'stream_context_set_params');
        $context->separateArrayForWrite();
        $params = self::requireParamsArray($params, 'stream_context_set_params');

        $exported = VmHttpBuildQuery::export($params);
        if (!\is_array($exported)) {
            throw new \TypeError(
                'stream_context_set_params(): Argument #2 ($params) must be of type array, '
                .VmStreamArg::debugTypeName($params).' given'
            );
        }

        if (\array_key_exists('notification', $exported)) {
            $notificationVar = $params->toArray()->find('notification');
            if (null !== $notificationVar) {
                VmStreamNotification::validateContextNotificationParam(
                    $notificationVar,
                    'stream_context_set_params'
                );
            }
        }

        self::replaceParamsHashTable($context, $exported);

        return true;
    }

    /**
     * HTTP/HTTPS wrapper options from a stream context (ext/standard/streams.c, #9752).
     *
     * @return array<string, mixed>
     */
    public static function httpWrapperOptions(Variable $context, string $wrapper = 'http'): array
    {
        $resolved = $context->resolveIndirect();
        if (!self::isRepresentation($resolved)) {
            return [];
        }

        $exported = VmHttpBuildQuery::export($resolved);
        if (!\is_array($exported)) {
            return [];
        }
        unset($exported[self::MARKER_KEY], $exported[self::PARAMS_MARKER_KEY]);
        if (!isset($exported[$wrapper]) || !\is_array($exported[$wrapper])) {
            return [];
        }

        return $exported[$wrapper];
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
