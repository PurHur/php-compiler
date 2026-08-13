<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\BuiltinParamNames;
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

    /** @var array<int, HashTable> live stream-context handles for get_resources() (#11104) */
    private static array $activeById = [];

    /** Process-wide default context (php-src php_stream_context_get(), #6367). */
    private static ?Variable $defaultContext = null;

    /**
     * php-src ext/standard/streams.c — first stream open materializes default context (#11104).
     */
    public static function ensureDefaultForStreamOpen(): void
    {
        self::ensureDefaultContext();
    }

    /**
     * @return list<Variable>
     */
    public static function activeContextVariables(): array
    {
        $out = [];
        foreach (self::$activeById as $ht) {
            $var = new Variable();
            $var->array($ht);
            $out[] = $var;
        }

        return $out;
    }

    private static function registerActive(int $id, HashTable $ht): void
    {
        $ht->addRef();
        self::$activeById[$id] = $ht;
    }

    /**
     * Build a VM stream-context array from caller options (issue #1377, #2457, #6815).
     *
     * Deep-copies nested option arrays like JIT {@see \PHPCompiler\JIT\Builtin\StreamContextRuntime}
     * so assigning the result does not share buckets with the input variable.
     *
     * Params (including notification Closures) are applied via {@see setParams} — same as
     * php-src parse_context_params() — not http_build_query export (#22815, re-#19696).
     */
    public static function createFromVmOptions(?Variable $optionsVar, ?Variable $paramsVar = null): HashTable
    {
        if (null !== $optionsVar) {
            $resolved = $optionsVar->resolveIndirect();
            if (Variable::TYPE_NULL === $resolved->type) {
                // php-src: null options → default empty context (ext/standard/streams.c, #13356)
            } elseif (Variable::TYPE_ARRAY !== $resolved->type) {
                self::throwCreateArrayTypeError(1, 'options', $resolved);
            } else {
                VmStreamContextOptions::validateOptionsVariable($optionsVar, 'stream_context_create');
                // Ensure nested option values are exportable scalars/arrays (shape already validated).
                $exported = VmHttpBuildQuery::export($resolved);
                if (!\is_array($exported)) {
                    self::throwCreateArrayTypeError(1, 'options', $resolved);
                }
            }
        }

        if (null !== $paramsVar) {
            $resolvedParams = $paramsVar->resolveIndirect();
            if (Variable::TYPE_NULL === $resolvedParams->type) {
                // php-src: null params → omitted (#13356)
                $paramsVar = null;
            } elseif (Variable::TYPE_ARRAY !== $resolvedParams->type) {
                self::throwCreateArrayTypeError(2, 'params', $resolvedParams);
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
        $ht->markResourceLikeHandle();
        self::registerActive($id, $ht);

        if (null !== $paramsVar && Variable::TYPE_ARRAY === $paramsVar->resolveIndirect()->type) {
            $contextVar = new Variable();
            $contextVar->array($ht);
            self::setParams($contextVar, $paramsVar);
        }

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
        self::registerActive($id, $ht);

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

    /** Zend stub parameter label for stream/context builtins (#24584, #30418). */
    public static function paramNameForArg(string $functionName, int $argNum, string $fallback = 'context'): string
    {
        $names = BuiltinParamNames::paramNamesForInternalFunction($functionName);
        if (null === $names || !isset($names[$argNum - 1])) {
            return $fallback;
        }

        return rtrim(ltrim($names[$argNum - 1], '&'), '=');
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
            $paramName = self::paramNameForArg($functionName, $argNum);
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type resource, %s given',
                $functionName,
                $argNum,
                $paramName,
                VmStreamArg::debugTypeName($resolved)
            ));
        }

        return $resolved;
    }

    /** Optional stream-context parameter on copy/rename/unlink/mkdir/rmdir (ext/standard/file.c / filestat.c). */
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
            $paramName = self::paramNameForArg($functionName, $argNum);
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type resource or null, %s given',
                $functionName,
                $argNum,
                $paramName,
                VmStreamArg::debugTypeName($resolved)
            ));
        }
    }

    private static function throwCreateArrayTypeError(int $argNum, string $label, Variable $resolved): void
    {
        throw new \TypeError(\sprintf(
            'stream_context_create(): Argument #%d ($%s) must be of type ?array, %s given',
            $argNum,
            $label,
            VmStreamArg::debugTypeName($resolved)
        ));
    }

    public static function requireOptionsArray(
        Variable $var,
        string $functionName,
        int $argNum = 2,
        string $expectedType = 'array'
    ): Variable {
        return self::requireContextArrayArg($var, $functionName, $argNum, 'options', $expectedType);
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
        string $label,
        string $expectedType = 'array'
    ): Variable {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $resolved->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type %s, %s given',
                $functionName,
                $argNum,
                $label,
                $expectedType,
                VmStreamArg::debugTypeName($resolved)
            ));
        }

        return $resolved;
    }

    /**
     * stream_context_get_default() — lazy singleton with optional merge (ext/standard/streams.c, #6367).
     *
     * php-src basic_functions.stub.php — ?array $options = null (#25381).
     */
    public static function getDefault(?Variable $optionsVar = null): Variable
    {
        $context = self::ensureDefaultContext();
        if (null !== $optionsVar) {
            $resolved = $optionsVar->resolveIndirect();
            if (Variable::TYPE_NULL !== $resolved->type) {
                self::setOptions(
                    $context,
                    self::requireOptionsArray($optionsVar, 'stream_context_get_default', 1, '?array')
                );
            }
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
     * Canonical options/params HashTable for a stream-context handle (#26762).
     *
     * ARG_SEND by-value used to zend_array_dup the context; mutators then wrote a fork
     * while get_options still read the caller's table. Always prefer {@see $activeById}.
     */
    private static function canonicalHashTable(Variable $context): HashTable
    {
        $id = self::idFrom($context);
        if (null !== $id && isset(self::$activeById[$id])) {
            return self::$activeById[$id];
        }
        $context->separateArrayForWrite();

        return $context->toArray();
    }

    /**
     * php-src streamsfuncs.c zend_argument_value_error(3, "cannot be null when argument #2 … is a string") (#30645).
     */
    public const SET_OPTION_OPTION_NAME_NULL_ON_STRING =
        'stream_context_set_option(): Argument #3 ($option_name) cannot be null when argument #2 ($wrapper_or_options) is a string';

    /**
     * php-src streamsfuncs.c zend_argument_value_error(4, "must be provided when argument #2 … is a string") (#30645).
     */
    public const SET_OPTION_VALUE_REQUIRED_ON_STRING =
        'stream_context_set_option(): Argument #4 ($value) must be provided when argument #2 ($wrapper_or_options) is a string';

    /**
     * php-src streamsfuncs.c zend_argument_value_error(3, "must be null when argument #2 … is an array") (#30645).
     */
    public const SET_OPTION_OPTION_NAME_MUST_BE_NULL_ON_ARRAY =
        'stream_context_set_option(): Argument #3 ($option_name) must be null when argument #2 ($wrapper_or_options) is an array';

    /**
     * php-src streamsfuncs.c zend_argument_value_error(4, "cannot be provided when argument #2 … is an array") (#30645).
     */
    public const SET_OPTION_VALUE_FORBIDDEN_ON_ARRAY =
        'stream_context_set_option(): Argument #4 ($value) cannot be provided when argument #2 ($wrapper_or_options) is an array';

    /**
     * stream_context_set_option() — singular or batch wrapper option write (ext/standard/streams.c, #3448, #30645).
     *
     * Two-arg array form merges a full options array; four-arg string form sets one wrapper option.
     * Incomplete string form / extra array-form args → Zend ValueError (not LogicException / silent true).
     *
     * $arg3/$arg4 PHP null means the argument was omitted; a Variable (including TYPE_NULL) was passed.
     */
    public static function setOption(Variable $context, Variable $arg2, ?Variable $arg3 = null, ?Variable $arg4 = null): bool
    {
        $context = self::requireRepresentation($context, 'stream_context_set_option');
        $resolved2 = $arg2->resolveIndirect();
        $optionIsNull = null === $arg3 || Variable::TYPE_NULL === $arg3->resolveIndirect()->type;
        $valueProvided = null !== $arg4;

        if (Variable::TYPE_ARRAY === $resolved2->type) {
            if (!$optionIsNull) {
                throw new \ValueError(self::SET_OPTION_OPTION_NAME_MUST_BE_NULL_ON_ARRAY);
            }
            if ($valueProvided) {
                throw new \ValueError(self::SET_OPTION_VALUE_FORBIDDEN_ON_ARRAY);
            }

            return self::setOptions($context, $arg2);
        }

        if ($optionIsNull) {
            throw new \ValueError(self::SET_OPTION_OPTION_NAME_NULL_ON_STRING);
        }
        if (!$valueProvided) {
            throw new \ValueError(self::SET_OPTION_VALUE_REQUIRED_ON_STRING);
        }

        $wrapperName = self::coerceOptionKeyString($resolved2, 'stream_context_set_option', 2, 'wrapper_or_options');
        $optionName = self::coerceOptionKeyString($arg3->resolveIndirect(), 'stream_context_set_option', 3, 'option_name');
        $exportedValue = VmHttpBuildQuery::export($arg4);
        if (!\is_scalar($exportedValue) && null !== $exportedValue && !\is_array($exportedValue)) {
            $exportedValue = '';
        }

        VmParseStr::mergeInto(self::canonicalHashTable($context), [
            $wrapperName => [$optionName => $exportedValue],
        ]);

        return true;
    }

    /**
     * stream_context_get_params() — options bag + params metadata (ext/standard/streams.c, #3448).
     */
    public static function getParamsHashTable(Variable $context): HashTable
    {
        $context = self::requireRepresentation($context, 'stream_context_get_params');
        $out = new HashTable();
        $optionsVar = new Variable();
        $optionsVar->array(self::getOptionsHashTable($context));
        $out->add('options', $optionsVar);

        $paramsSlot = $context->toArray()->find(self::PARAMS_MARKER_KEY);
        if (null !== $paramsSlot) {
            $paramsHt = $paramsSlot->resolveIndirect()->toArray();
            foreach ($paramsHt->iterateKeyed(true) as [$key, $value]) {
                $k = $key->resolveIndirect();
                if (Variable::TYPE_STRING !== $k->type) {
                    continue;
                }
                $name = $k->toString();
                if ('options' === $name) {
                    continue;
                }
                $copy = new Variable();
                $copy->copyFrom($value);
                $out->add($name, $copy);
            }
        }

        return $out;
    }

    private static function coerceOptionKeyString(
        Variable $resolved,
        string $functionName,
        int $argNum,
        string $label
    ): string {
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

        throw new \TypeError(\sprintf(
            '%s(): Argument #%d ($%s) must be of type string, %s given',
            $functionName,
            $argNum,
            $label,
            VmStreamArg::debugTypeName($resolved)
        ));
    }

    /**
     * Merge options into an existing stream context (issue #6517).
     */
    public static function setOptions(Variable $context, Variable $options): bool
    {
        $context = self::requireRepresentation($context, 'stream_context_set_options');
        $options = self::requireOptionsArray($options, 'stream_context_set_options');
        VmStreamContextOptions::validateOptionsVariable($options, 'stream_context_set_options');

        $exported = VmHttpBuildQuery::export($options);
        if (!\is_array($exported)) {
            throw new \TypeError(
                'stream_context_set_options(): Argument #2 ($options) must be of type array, '
                .VmStreamArg::debugTypeName($options).' given'
            );
        }

        VmParseStr::mergeInto(self::canonicalHashTable($context), $exported);

        return true;
    }

    /**
     * Replace params on an existing stream context (issue #6122, #8058, #19696).
     *
     * php-src parse_context_params(): store notification callable as-is (do not round-trip
     * through http_build_query export — Closures throw LogicException), merge options bag
     * into wrapper options. VM HashTable only — no host Zend params sync (bootstrap/M5).
     */
    public static function setParams(Variable $context, Variable $params): bool
    {
        $context = self::requireRepresentation($context, 'stream_context_set_params');
        // Bind the Variable to the canonical live HT before param writes (#26762).
        $canonical = self::canonicalHashTable($context);
        if ($context->toArray() !== $canonical) {
            $context->array($canonical);
        }
        $context->separateArrayForWrite();
        $params = self::requireParamsArray($params, 'stream_context_set_params');

        $paramsHt = $params->toArray();

        $notificationVar = $paramsHt->find('notification');
        if (null !== $notificationVar) {
            VmStreamNotification::validateContextNotificationParam(
                $notificationVar,
                'stream_context_set_params'
            );
            self::upsertParamSlot($context, 'notification', $notificationVar);
        }

        $optionsVar = $paramsHt->find('options');
        if (null !== $optionsVar) {
            $resolvedOpts = $optionsVar->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $resolvedOpts->type) {
                throw new \TypeError('Invalid stream/context parameter');
            }
            VmStreamContextOptions::validateOptionsVariable($optionsVar, 'stream_context_set_params');
            $exported = VmHttpBuildQuery::export($optionsVar);
            if (!\is_array($exported)) {
                throw new \TypeError('Invalid stream/context parameter');
            }
            VmParseStr::mergeInto($context->toArray(), $exported);
        }

        // Extra string keys (ignored by php-src) — keep Variable copies for existing tests.
        foreach ($paramsHt->iterateKeyed(true) as [$key, $value]) {
            $k = $key->resolveIndirect();
            if (Variable::TYPE_STRING !== $k->type) {
                continue;
            }
            $name = $k->toString();
            if ('notification' === $name || 'options' === $name) {
                continue;
            }
            self::upsertParamSlot($context, $name, $value);
        }

        return true;
    }

    /**
     * HTTP/HTTPS wrapper options from a stream context (ext/standard/streams.c, #9752).
     *
     * Export the options bag only — the params bag may hold a notification Closure
     * (php-src parse_context_params); feeding the whole context through
     * {@see VmHttpBuildQuery::export} throws LogicException (#22815, re-#19696).
     *
     * @return array<string, mixed>
     */
    public static function httpWrapperOptions(Variable $context, string $wrapper = 'http'): array
    {
        $resolved = $context->resolveIndirect();
        if (!self::isRepresentation($resolved)) {
            return [];
        }

        $optionsVar = new Variable();
        $optionsVar->array(self::getOptionsHashTable($resolved));
        $exported = VmHttpBuildQuery::export($optionsVar);
        if (!\is_array($exported)) {
            return [];
        }
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
        $source = self::canonicalHashTable($context);
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

    /**
     * Merge one params key into the context params bag without scalar export (#19696).
     * Preserves Closure / object notification callables for stream_context_get_params().
     */
    private static function upsertParamSlot(Variable $context, string $name, Variable $value): void
    {
        $contextArray = $context->toArray();
        $paramsSlot = $contextArray->find(self::PARAMS_MARKER_KEY);
        $copy = new Variable();
        $copy->copyFrom($value);

        if (null === $paramsSlot) {
            // Populate before wrapping — Variable::array() addRefs the HT (#19696).
            $paramsHt = new HashTable();
            $paramsHt->add($name, $copy);
            $slot = new Variable();
            $slot->array($paramsHt);
            $contextArray->add(self::PARAMS_MARKER_KEY, $slot);

            return;
        }

        $paramsSlot->separateArrayForWrite();
        $paramsHt = $paramsSlot->resolveIndirect()->toArray();
        $existing = $paramsHt->find($name);
        if (null !== $existing) {
            $existing->copyFrom($copy);
        } else {
            $paramsHt->add($name, $copy);
        }
    }
}
