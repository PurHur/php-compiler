<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\MethodVisibility;
use PHPCompiler\VM;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseEntry;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\InterfaceCheck;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\TypedPropertyCheck;
use PHPCompiler\VM\Variable;

/**
 * Export/import VM values for json_encode() / json_decode() delegation.
 *
 * Tracks {@see lastError()} / {@see lastErrorMsg()} for VM parity with Zend ext/json (issue #3175).
 */
final class VmJson
{
    /** JSON_ERROR_INF_OR_NAN — non-finite float (Zend ext/json/php_json_encoder.c). */
    public const ERROR_INF_OR_NAN = 7;

    /** JSON_ERROR_UNSUPPORTED_TYPE — object without JsonSerializable (Zend ext/json). */
    public const ERROR_UNSUPPORTED_TYPE = 8;

    /** JSON_ERROR_RECURSION — circular array/object reference (Zend ext/json/php_json.c). */
    public const ERROR_RECURSION = 6;

    /** JSON_ERROR_DEPTH — maximum stack depth exceeded (Zend ext/json/php_json.c). */
    public const ERROR_DEPTH = 1;

    /** Last JSON_ERROR_* from VM json_* (Zend ext/json/php_json.c). */
    private static int $lastError = 0;

    public static function lastError(): int
    {
        return self::$lastError;
    }

    public static function setLastError(int $code): void
    {
        self::$lastError = $code;
    }

    public static function lastErrorMsg(): string
    {
        return self::errorMsgForCode(self::$lastError);
    }

    public static function errorMsgForCode(int $code): string
    {
        return match ($code) {
            0 => 'No error',
            1 => 'Maximum stack depth exceeded',
            4 => 'Syntax error',
            5 => 'Malformed UTF-8 characters, possibly incorrectly encoded',
            self::ERROR_RECURSION => 'Recursion detected',
            self::ERROR_INF_OR_NAN => 'Inf and NaN cannot be JSON encoded',
            self::ERROR_UNSUPPORTED_TYPE => 'Type is not supported',
            default => 'Unknown error',
        };
    }

    public static function syncLastErrorFromHost(): void
    {
        self::$lastError = \json_last_error();
    }

    public static function import(mixed $value): Variable
    {
        return self::importDecoded($value, true, null);
    }

    /**
     * Materialize host json_decode() output into VM values (#7188).
     *
     * When {@code $assoc} is false, JSON objects become stdClass instances (php-src ext/json).
     */
    public static function importDecoded(mixed $value, bool $assoc, ?Context $ctx): Variable
    {
        if ($assoc) {
            return self::importAssoc($value);
        }

        return self::importObjectMode($value, $ctx);
    }

    private static function importAssoc(mixed $value): Variable
    {
        $var = new Variable();
        if (null === $value) {
            $var->null();

            return $var;
        }
        if (\is_bool($value)) {
            $var->bool($value);

            return $var;
        }
        if (\is_int($value)) {
            $var->int($value);

            return $var;
        }
        if (\is_float($value)) {
            $var->float($value);

            return $var;
        }
        if (\is_string($value)) {
            $var->string($value);

            return $var;
        }
        if (!\is_array($value)) {
            throw new \LogicException(
                'json_decode() result type not supported in this compiler build'
            );
        }
        $ht = new \PHPCompiler\VM\HashTable();
        $isList = array_is_list($value);
        foreach ($value as $key => $item) {
            $slot = self::importAssoc($item);
            if ($isList) {
                $ht->addIndex((int) $key, $slot);
            } else {
                if (!\is_string($key) && !\is_int($key)) {
                    throw new \LogicException(
                        'json_decode() only supports string keys in this compiler build'
                    );
                }
                $ht->add((string) $key, $slot);
            }
        }
        $var->array($ht);

        return $var;
    }

    private static function importObjectMode(mixed $value, ?Context $ctx): Variable
    {
        $var = new Variable();
        if (null === $value) {
            $var->null();

            return $var;
        }
        if (\is_bool($value)) {
            $var->bool($value);

            return $var;
        }
        if (\is_int($value)) {
            $var->int($value);

            return $var;
        }
        if (\is_float($value)) {
            $var->float($value);

            return $var;
        }
        if (\is_string($value)) {
            $var->string($value);

            return $var;
        }
        if (\is_array($value)) {
            $ht = new \PHPCompiler\VM\HashTable();
            $isList = array_is_list($value);
            foreach ($value as $key => $item) {
                $slot = self::importObjectMode($item, $ctx);
                if ($isList) {
                    $ht->addIndex((int) $key, $slot);
                } else {
                    if (!\is_string($key) && !\is_int($key)) {
                        throw new \LogicException(
                            'json_decode() only supports string keys in this compiler build'
                        );
                    }
                    $ht->add((string) $key, $slot);
                }
            }
            $var->array($ht);

            return $var;
        }
        if ($value instanceof \stdClass) {
            if (null === $ctx || !isset($ctx->classes['stdclass'])) {
                throw new \LogicException('stdClass is not registered');
            }
            $object = new ObjectEntry($ctx->classes['stdclass']);
            $object->constructed = true;
            foreach ((array) $value as $key => $item) {
                $object->allocateProperty((string) $key)
                    ->copyFrom(self::importObjectMode($item, $ctx));
            }
            $var->object($object);

            return $var;
        }

        throw new \LogicException(
            'json_decode() result type not supported in this compiler build'
        );
    }

    public static function export(
        Variable $v,
        ?Context $ctx = null,
        ?VM $vm = null,
        ?Frame $frame = null,
        int $maxDepth = 512,
        int $depth = 0
    ): mixed {
        return self::exportValue($v, $ctx, $vm, $frame, new \SplObjectStorage(), $maxDepth, $depth);
    }

    private static function exportValue(
        Variable $v,
        ?Context $ctx,
        ?VM $vm,
        ?Frame $frame,
        \SplObjectStorage $visited,
        int $maxDepth,
        int $depth
    ): mixed {
        $v = $v->resolveIndirect();
        if (is_resource_::isResource($v)) {
            throw new VmJsonExportException(self::ERROR_UNSUPPORTED_TYPE);
        }
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
                $arrayDepth = $depth + 1;
                if ($arrayDepth > $maxDepth) {
                    throw new VmJsonExportException(self::ERROR_DEPTH);
                }
                $ht = $v->toArray();
                if ($visited->contains($ht)) {
                    throw new VmJsonExportException(self::ERROR_RECURSION);
                }
                $visited->attach($ht);
                try {
                    $out = [];
                    foreach ($ht->iterateKeyed(true) as [$key, $value]) {
                        $k = $key->resolveIndirect();
                        if (Variable::TYPE_STRING === $k->type) {
                            $out[$k->toString()] = self::exportValue(
                                $value,
                                $ctx,
                                $vm,
                                $frame,
                                $visited,
                                $maxDepth,
                                $arrayDepth
                            );
                        } elseif (Variable::TYPE_INTEGER === $k->type) {
                            $out[$k->toInt()] = self::exportValue(
                                $value,
                                $ctx,
                                $vm,
                                $frame,
                                $visited,
                                $maxDepth,
                                $arrayDepth
                            );
                        } else {
                            throw new \LogicException(
                                'json_encode() only supports string or integer keys in this compiler build'
                            );
                        }
                    }

                    return $out;
                } finally {
                    $visited->detach($ht);
                }
            case Variable::TYPE_ENUM_CASE:
                return self::exportEnumCase($v->toEnumCase(), $ctx, $vm, $frame, $visited, $maxDepth, $depth);
            case Variable::TYPE_OBJECT:
                $objectDepth = $depth + 1;
                if ($objectDepth > $maxDepth) {
                    throw new VmJsonExportException(self::ERROR_DEPTH);
                }
                if (null === $ctx || null === $vm) {
                    throw new \LogicException(
                        'json_encode() value type not supported in this compiler build'
                    );
                }
                $object = $v->toObject();
                if ($visited->contains($object)) {
                    throw new VmJsonExportException(self::ERROR_RECURSION);
                }
                $visited->attach($object);
                try {
                    if (EnumCaseSupport::isEnumCase($object)) {
                        $backing = new Variable();
                        if (null !== $object->enumCaseValue) {
                            $backing->copyFrom($object->enumCaseValue);
                        } else {
                            $backing->string('');
                        }

                        return self::exportEnumCase(
                            new EnumCaseEntry(
                                $object->class,
                                $object->enumCaseName ?? '',
                                $backing
                            ),
                            $ctx,
                            $vm,
                            $frame,
                            $visited,
                            $maxDepth,
                            $objectDepth
                        );
                    }
                    if (!InterfaceCheck::entryImplements($object->class, 'jsonserializable', $ctx)) {
                        return self::exportObjectPublicProperties(
                            $object,
                            $ctx,
                            $vm,
                            $frame,
                            $visited,
                            $maxDepth,
                            $objectDepth
                        );
                    }
                    if (!$vm->hasInstanceMethod($object->class, 'jsonserialize')) {
                        throw new \Error(
                            'Call to undefined method '.$object->class->name.'::jsonSerialize()'
                        );
                    }
                    $serialized = $vm->invokeInstanceMethod($object, 'jsonSerialize')->resolveIndirect();

                    return self::exportValue(
                        $serialized,
                        $ctx,
                        $vm,
                        $frame,
                        $visited,
                        $maxDepth,
                        $objectDepth
                    );
                } finally {
                    $visited->detach($object);
                }
            default:
                throw new VmJsonExportException(self::ERROR_UNSUPPORTED_TYPE);
        }
    }

    /**
     * Zend ext/json/php_json.c — public properties only; empty hash encodes as {} (#6879).
     *
     * Stringable/__toString() is not consulted; objects with no public props become {}.
     */
    private static function exportObjectPublicProperties(
        ObjectEntry $object,
        Context $ctx,
        VM $vm,
        ?Frame $frame,
        \SplObjectStorage $visited,
        int $maxDepth,
        int $depth
    ): \stdClass {
        $out = new \stdClass();
        if (null !== $frame) {
            foreach ($vm->collectPublicPropertiesForSerialize($object, $frame) as $name => $prop) {
                $out->$name = self::exportValue(
                    $prop,
                    $ctx,
                    $vm,
                    $frame,
                    $visited,
                    $maxDepth,
                    $depth
                );
            }

            return $out;
        }
        /** @var array<string, true> $seenLc */
        $seenLc = [];
        foreach (array_reverse(VmReflection::classHierarchyChain($object->class, $ctx)) as $class) {
            foreach ($class->properties as $meta) {
                $lc = strtolower($meta->name);
                if (isset($seenLc[$lc])) {
                    continue;
                }
                $seenLc[$lc] = true;
                if (!MethodVisibility::isPublic($meta->visibility)) {
                    continue;
                }
                if ($meta->propertyHookVirtual && null === $meta->getHookMethodLc) {
                    continue;
                }
                if (!$object->hasProperty($meta->name)) {
                    continue;
                }
                $value = $object->getProperty($meta->name)->resolveIndirect();
                if (TypedPropertyCheck::omitFromPropertyEnumeration($value)) {
                    continue;
                }
                $copy = new Variable();
                $copy->copyFrom($value);
                $name = $meta->name;
                $out->$name = self::exportValue(
                    $copy,
                    $ctx,
                    $vm,
                    $frame,
                    $visited,
                    $maxDepth,
                    $depth
                );
            }
        }
        foreach ($object->getRawProperties() as $name => $prop) {
            if (isset($seenLc[strtolower($name)])) {
                continue;
            }
            $value = $prop->resolveIndirect();
            if (TypedPropertyCheck::omitFromPropertyEnumeration($value)) {
                continue;
            }
            $copy = new Variable();
            $copy->copyFrom($prop);
            $out->$name = self::exportValue(
                $copy,
                $ctx,
                $vm,
                $frame,
                $visited,
                $maxDepth,
                $depth
            );
        }

        return $out;
    }

    /**
     * Zend ext/json/php_json.c — JsonSerializable hook before default enum scalar encoding (#6880).
     */
    private static function exportEnumCase(
        EnumCaseEntry $case,
        ?Context $ctx,
        ?VM $vm,
        ?Frame $frame,
        \SplObjectStorage $visited,
        int $maxDepth,
        int $depth
    ): mixed {
        if (null !== $ctx && null !== $vm
            && InterfaceCheck::entryImplements($case->enumClass, 'jsonserializable', $ctx)) {
            $caseVar = new Variable(Variable::TYPE_ENUM_CASE);
            $caseVar->enumCase($case);
            $object = EnumCaseSupport::receiverForInstanceMethod($caseVar)->toObject();
            if (!$vm->hasInstanceMethod($object->class, 'jsonserialize')) {
                throw new \Error(
                    'Call to undefined method '.$object->class->name.'::jsonSerialize()'
                );
            }
            $serialized = $vm->invokeInstanceMethod($object, 'jsonSerialize')->resolveIndirect();

            return self::exportValue(
                $serialized,
                $ctx,
                $vm,
                $frame,
                $visited,
                $maxDepth,
                $depth
            );
        }

        if (null === $case->enumClass->backedType) {
            throw new VmJsonExportException(self::ERROR_UNSUPPORTED_TYPE);
        }
        $backing = $case->backingValue->resolveIndirect();

        return match ($case->enumClass->backedType) {
            'int' => $backing->toInt(),
            'string' => $backing->toString(),
            default => throw new \LogicException(
                'json_encode() unsupported enum backing type in this compiler build'
            ),
        };
    }
}

/** json_encode() export failure with a JSON_ERROR_* code (issue #3370). */
final class VmJsonExportException extends \RuntimeException
{
    public function __construct(public readonly int $errorCode)
    {
        parent::__construct(VmJson::errorMsgForCode($errorCode));
    }
}
