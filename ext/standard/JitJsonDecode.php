<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringJsonDecode;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

final class JitJsonDecode
{
    public static function materializeNull(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

        return $ptr;
    }

    /**
     * @param int|float|bool|string $scalar
     */
    public static function materializeScalar(Context $context, $scalar): Value
    {
        $slot = JitValueBox::alloc($context);
        if (\is_bool($scalar)) {
            JitValueBox::writeLong(
                $context,
                $slot,
                $context->getTypeFromString('int64')->constInt($scalar ? 1 : 0, false)
            );
        } elseif (\is_int($scalar)) {
            JitValueBox::writeLong(
                $context,
                $slot,
                $context->getTypeFromString('int64')->constInt($scalar, false)
            );
        } elseif (\is_float($scalar)) {
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                JitValueBox::pointer($context, $slot),
                $context->getTypeFromString('double')->constReal($scalar, false)
            );
        } else {
            $str = $context->builder->load($context->constantStringFromString((string) $scalar));
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                JitValueBox::pointer($context, $slot),
                $str
            );
        }

        return JitValueBox::pointer($context, $slot);
    }

    public static function materializeArray(Context $context, array $data): Value
    {
        $ht = self::buildHashtableFromPhp($context, $data);
        $context->refcount->addref($ht);

        return $ht;
    }

    public static function decodeRuntime(Context $context, JITVariable $json): Value
    {
        StringJsonDecode::ensureLinked($context);
        // Soft-null DEP+coerce on 8.4 — Zend Z_PARAM_STR (#21223; reverts #18852 TypeError).
        $jsonString = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $json,
            'json_decode',
            0,
            'json'
        );

        return self::decodeRuntimeString($context, $jsonString);
    }

    public static function decodeRuntimeString(Context $context, Value $jsonString): Value
    {
        // __compiler_json_decode returns __value__* (Unserialize #20785 / #20829 ABI).
        return $context->builder->call(
            $context->lookupFunction('__compiler_json_decode'),
            $jsonString
        );
    }

    public static function decodeRuntimeObjectMode(Context $context, JITVariable $json): Value
    {
        throw new \LogicException(
            'json_decode() assoc=false requires a compile-time JSON string in JIT/AOT in this compiler build'
        );
    }

    /**
     * @param int|float|bool|string|array<string|int, mixed>|\stdClass|null $decoded
     */
    public static function materializeDecoded(Context $context, mixed $decoded, bool $assoc): Value
    {
        if (null === $decoded) {
            return self::materializeNull($context);
        }
        if (\is_bool($decoded) || \is_int($decoded) || \is_float($decoded) || \is_string($decoded)) {
            return self::materializeScalar($context, $decoded);
        }
        if (!$assoc) {
            self::predefineStdClassTree($context, $decoded);
        }
        if ($assoc) {
            if (!\is_array($decoded)) {
                throw new \LogicException('json_decode() assoc=true expects array result');
            }

            return self::materializeArray($context, $decoded);
        }
        if (\is_array($decoded)) {
            return self::materializeArrayDeepObjectMode($context, $decoded);
        }
        if ($decoded instanceof \stdClass) {
            return self::materializeStdClass($context, $decoded);
        }

        throw new \LogicException('json_decode() result type not supported for JIT materialization');
    }

    /**
     * @param array<string|int, mixed> $data
     */
    private static function materializeArrayDeepObjectMode(Context $context, array $data): Value
    {
        $ht = self::buildHashtableFromPhpObjectMode($context, $data);
        $context->refcount->addref($ht);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeHashtable'), $ptr, $ht);

        return $ptr;
    }

    /**
     * @param array<string|int, mixed> $data
     */
    private static function buildHashtableFromPhpObjectMode(Context $context, array $data): Value
    {
        $ht = HashTableHelper::alloc($context);
        $isList = array_is_list($data);
        foreach ($data as $key => $value) {
            if ($isList) {
                self::storeIndexValueObjectMode($context, $ht, (int) $key, $value);
                continue;
            }
            $keyStr = $context->builder->load($context->constantStringFromString((string) $key));
            self::storeStringKeyValueObjectMode($context, $ht, $keyStr, $value);
        }

        return $ht;
    }

    private static function storeIndexValueObjectMode(Context $context, Value $ht, int $index, mixed $value): void
    {
        if (\is_array($value)) {
            $child = self::buildHashtableFromPhpObjectMode($context, $value);
            $context->builder->call(
                $context->lookupFunction('__hashtable__setHashtableAt'),
                $ht,
                $context->getTypeFromString('size_t')->constInt($index, false),
                $child
            );

            return;
        }
        if ($value instanceof \stdClass) {
            $obj = self::allocateStdClassFromPhp($context, $value);
            $context->builder->call(
                $context->lookupFunction('__hashtable__setObjectAt'),
                $ht,
                $context->getTypeFromString('size_t')->constInt($index, false),
                $obj
            );

            return;
        }
        if (\is_bool($value)) {
            $context->builder->call(
                $context->lookupFunction('__hashtable__setBoolAt'),
                $ht,
                $context->getTypeFromString('size_t')->constInt($index, false),
                $context->getTypeFromString('int1')->constInt($value ? 1 : 0, false)
            );

            return;
        }
        if (\is_int($value)) {
            $context->builder->call(
                $context->lookupFunction('__hashtable__setLongAt'),
                $ht,
                $context->getTypeFromString('size_t')->constInt($index, false),
                $context->getTypeFromString('int64')->constInt($value, false)
            );

            return;
        }
        if (\is_float($value)) {
            $context->builder->call(
                $context->lookupFunction('__hashtable__setDoubleAt'),
                $ht,
                $context->getTypeFromString('size_t')->constInt($index, false),
                $context->getTypeFromString('double')->constReal($value, false)
            );

            return;
        }
        if (null === $value) {
            $context->builder->call(
                $context->lookupFunction('__hashtable__setNullAt'),
                $ht,
                $context->getTypeFromString('size_t')->constInt($index, false)
            );

            return;
        }
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringAt'),
            $ht,
            $context->getTypeFromString('size_t')->constInt($index, false),
            $context->builder->load($context->constantStringFromString((string) $value))
        );
    }

    private static function storeStringKeyValueObjectMode(Context $context, Value $ht, Value $keyStr, mixed $value): void
    {
        if (\is_array($value)) {
            $child = self::buildHashtableFromPhpObjectMode($context, $value);
            $context->builder->call(
                $context->lookupFunction('__hashtable__setStringKeyHashtable'),
                $ht,
                $keyStr,
                $child
            );

            return;
        }
        if (\is_bool($value)) {
            $context->builder->call(
                $context->lookupFunction('__hashtable__setStringKeyBool'),
                $ht,
                $keyStr,
                $context->getTypeFromString('int1')->constInt($value ? 1 : 0, false)
            );

            return;
        }
        if (\is_int($value)) {
            $context->builder->call(
                $context->lookupFunction('__hashtable__setStringKeyLong'),
                $ht,
                $keyStr,
                $context->getTypeFromString('int64')->constInt($value, false)
            );

            return;
        }
        if (\is_float($value)) {
            $context->builder->call(
                $context->lookupFunction('__hashtable__setStringKeyDouble'),
                $ht,
                $keyStr,
                $context->getTypeFromString('double')->constReal($value, false)
            );

            return;
        }
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $ht,
            $keyStr,
            self::scalarToJitString($context, $value)
        );
    }

    private static function materializeStdClass(Context $context, \stdClass $obj): Value
    {
        $objectPtr = self::allocateStdClassFromPhp($context, $obj);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $objectPtr
        );

        return $ptr;
    }

    private static function allocateStdClassFromPhp(Context $context, \stdClass $obj): Value
    {
        $classId = $context->type->object->lookup('stdclass');
        $objectPtr = $context->type->object->allocate($classId);
        $context->type->object->markObjectConstructed($objectPtr);
        $context->type->object->initializePropertySlotsNull($objectPtr, $classId);
        foreach ((array) $obj as $prop => $value) {
            $propName = (string) $prop;
            $context->type->object->storeInstanceProperty(
                $objectPtr,
                'stdClass',
                $propName,
                self::materializeDecodedPropertyValue($context, $value)
            );
        }

        return $objectPtr;
    }

    /**
     * Register every dynamic property on stdClass before any allocate() (#7188).
     *
     * defineProperty() during nested materialization used to grow the shared stdclass
     * layout after parent objects were malloc'd with too few slots → AOT segfault.
     */
    private static function predefineStdClassTree(Context $context, mixed $value): void
    {
        if ($value instanceof \stdClass) {
            $classId = $context->type->object->lookup('stdclass');
            /** @var list<string> $compositeProps nested object/array keys first (#7188 slot layout) */
            $compositeProps = [];
            /** @var list<string> $scalarProps */
            $scalarProps = [];
            foreach ((array) $value as $prop => $item) {
                $propName = (string) $prop;
                if ($item instanceof \stdClass || \is_array($item)) {
                    $compositeProps[] = $propName;
                    continue;
                }
                $scalarProps[] = $propName;
            }
            foreach ($compositeProps as $propName) {
                if (!$context->type->object->hasProperty($classId, $propName)) {
                    $context->type->object->defineProperty(
                        $classId,
                        $propName,
                        \PHPCompiler\JIT\Variable::TYPE_VALUE
                    );
                }
                self::predefineStdClassTree($context, $value->{$propName});
            }
            foreach ($scalarProps as $propName) {
                if (!$context->type->object->hasProperty($classId, $propName)) {
                    $context->type->object->defineProperty(
                        $classId,
                        $propName,
                        \PHPCompiler\JIT\Variable::TYPE_VALUE
                    );
                }
            }

            return;
        }
        if (!\is_array($value)) {
            return;
        }
        foreach ($value as $item) {
            self::predefineStdClassTree($context, $item);
        }
    }

    private static function materializeDecodedPropertyValue(Context $context, mixed $value): \PHPCompiler\JIT\Variable
    {
        if (null === $value) {
            return new \PHPCompiler\JIT\Variable(
                $context,
                \PHPCompiler\JIT\Variable::TYPE_NULL,
                \PHPCompiler\JIT\Variable::KIND_VALUE,
                $context->getTypeFromString('void*')->constNull()
            );
        }
        if (\is_bool($value)) {
            $i1 = $context->getTypeFromString('int1');

            return new \PHPCompiler\JIT\Variable(
                $context,
                \PHPCompiler\JIT\Variable::TYPE_NATIVE_BOOL,
                \PHPCompiler\JIT\Variable::KIND_VALUE,
                $i1->constInt($value ? 1 : 0, false)
            );
        }
        if (\is_int($value)) {
            return new \PHPCompiler\JIT\Variable(
                $context,
                \PHPCompiler\JIT\Variable::TYPE_NATIVE_LONG,
                \PHPCompiler\JIT\Variable::KIND_VALUE,
                $context->getTypeFromString('int64')->constInt($value, false)
            );
        }
        if (\is_float($value)) {
            return new \PHPCompiler\JIT\Variable(
                $context,
                \PHPCompiler\JIT\Variable::TYPE_NATIVE_DOUBLE,
                \PHPCompiler\JIT\Variable::KIND_VALUE,
                $context->getTypeFromString('double')->constReal($value, false)
            );
        }
        if (\is_string($value)) {
            return new \PHPCompiler\JIT\Variable(
                $context,
                \PHPCompiler\JIT\Variable::TYPE_STRING,
                \PHPCompiler\JIT\Variable::KIND_VALUE,
                $context->builder->load($context->constantStringFromString($value))
            );
        }
        if (\is_array($value)) {
            $ht = self::buildHashtableFromPhpObjectMode($context, $value);
            $context->refcount->addref($ht);

            return new \PHPCompiler\JIT\Variable(
                $context,
                \PHPCompiler\JIT\Variable::TYPE_HASHTABLE,
                \PHPCompiler\JIT\Variable::KIND_VALUE,
                $ht
            );
        }
        if ($value instanceof \stdClass) {
            $obj = self::allocateStdClassFromPhp($context, $value);
            $context->refcount->addref($obj);

            return new \PHPCompiler\JIT\Variable(
                $context,
                \PHPCompiler\JIT\Variable::TYPE_OBJECT,
                \PHPCompiler\JIT\Variable::KIND_VALUE,
                $obj
            );
        }

        throw new \LogicException('json_decode() property type not supported for JIT materialization');
    }

    /**
     * Assoc-mode hashtable from decoded PHP array (#24116).
     *
     * Integer keys use packed {@see __hashtable__set*At} (Zend json_decode lists);
     * string keys use the associative path. Scalars keep their JSON types — not
     * stringified — matching {@see VmJson::importAssoc} / object-mode materialize.
     *
     * @param array<string|int, mixed> $data
     */
    private static function buildHashtableFromPhp(Context $context, array $data): Value
    {
        $ht = HashTableHelper::alloc($context);
        foreach ($data as $key => $value) {
            if (\is_int($key)) {
                self::storeIndexValueAssoc($context, $ht, $key, $value);
                continue;
            }
            $keyStr = $context->builder->load($context->constantStringFromString((string) $key));
            self::storeStringKeyValueAssoc($context, $ht, $keyStr, $value);
        }

        return $ht;
    }

    private static function storeIndexValueAssoc(Context $context, Value $ht, int $index, mixed $value): void
    {
        $idx = $context->getTypeFromString('size_t')->constInt($index, false);
        if (\is_array($value)) {
            $child = self::buildHashtableFromPhp($context, $value);
            $context->builder->call(
                $context->lookupFunction('__hashtable__setHashtableAt'),
                $ht,
                $idx,
                $child
            );

            return;
        }
        if (\is_bool($value)) {
            $context->builder->call(
                $context->lookupFunction('__hashtable__setBoolAt'),
                $ht,
                $idx,
                $context->getTypeFromString('int1')->constInt($value ? 1 : 0, false)
            );

            return;
        }
        if (\is_int($value)) {
            $context->builder->call(
                $context->lookupFunction('__hashtable__setLongAt'),
                $ht,
                $idx,
                $context->getTypeFromString('int64')->constInt($value, false)
            );

            return;
        }
        if (\is_float($value)) {
            $context->builder->call(
                $context->lookupFunction('__hashtable__setDoubleAt'),
                $ht,
                $idx,
                $context->getTypeFromString('double')->constReal($value, false)
            );

            return;
        }
        if (null === $value) {
            $context->builder->call(
                $context->lookupFunction('__hashtable__setNullAt'),
                $ht,
                $idx
            );

            return;
        }
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringAt'),
            $ht,
            $idx,
            $context->builder->load($context->constantStringFromString((string) $value))
        );
    }

    private static function storeStringKeyValueAssoc(Context $context, Value $ht, Value $keyStr, mixed $value): void
    {
        if (\is_array($value)) {
            $child = self::buildHashtableFromPhp($context, $value);
            $context->builder->call(
                $context->lookupFunction('__hashtable__setStringKeyHashtable'),
                $ht,
                $keyStr,
                $child
            );

            return;
        }
        if (\is_bool($value)) {
            $context->builder->call(
                $context->lookupFunction('__hashtable__setStringKeyBool'),
                $ht,
                $keyStr,
                $context->getTypeFromString('int1')->constInt($value ? 1 : 0, false)
            );

            return;
        }
        if (\is_int($value)) {
            $context->builder->call(
                $context->lookupFunction('__hashtable__setStringKeyLong'),
                $ht,
                $keyStr,
                $context->getTypeFromString('int64')->constInt($value, false)
            );

            return;
        }
        if (\is_float($value)) {
            $context->builder->call(
                $context->lookupFunction('__hashtable__setStringKeyDouble'),
                $ht,
                $keyStr,
                $context->getTypeFromString('double')->constReal($value, false)
            );

            return;
        }
        if (null === $value) {
            $context->builder->call(
                $context->lookupFunction('__hashtable__setStringKeyNull'),
                $ht,
                $keyStr
            );

            return;
        }
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $ht,
            $keyStr,
            $context->builder->load($context->constantStringFromString((string) $value))
        );
    }

    private static function scalarToJitString(Context $context, mixed $value): Value
    {
        if (\is_bool($value)) {
            $literal = $value ? '1' : '';
        } elseif (null === $value) {
            $literal = '';
        } elseif (\is_int($value) || \is_float($value)) {
            $literal = (string) $value;
        } elseif (\is_string($value)) {
            $literal = $value;
        } else {
            throw new \LogicException('json_decode() scalar type not supported for JIT materialization');
        }

        return $context->builder->load($context->constantStringFromString($literal));
    }
}
