<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal as FuncInternal;
use PHPCompiler\Func\PHP as PhpFunc;
use PHPCompiler\MethodVisibility;
use PHPCompiler\VM\BackedEnum;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\ext\spl\SplArraySerializeSupport;
use PHPCompiler\VM\DateIntervalSupport;
use PHPCompiler\VM\DateTimeSupport;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ResourceSupport;
use PHPCompiler\VM\TypedPropertyCheck;
use PHPCompiler\VM\Variable;

/**
 * serialize() / unserialize() hooks: __serialize/__unserialize (#1365),
 * legacy __sleep/__wakeup and Serializable (#3287).
 *
 * php-src: ext/standard/var.c, ext/standard/var_unserializer.c
 */
final class VmSerialize
{
    public static function serializeValue(Context $ctx, Variable $value, ?Frame $frame = null): string
    {
        $value = $value->resolveIndirect();
        $resourceWire = self::serializeResourceWire($value);
        if (null !== $resourceWire) {
            return $resourceWire;
        }
        $enumRef = self::enumCaseRefFromVariable($value);
        if (null !== $enumRef) {
            return self::encodeEnumCaseLiteral($enumRef->className, $enumRef->caseName);
        }
        if (Variable::TYPE_OBJECT === $value->type) {
            if (VmClosureCall::isClosure($value)) {
                throw new \Exception("Serialization of 'Closure' is not allowed");
            }
            $entry = $value->toObject();
            $lcClass = strtolower($entry->class->name);
            if (DateTimeSupport::CLASS_DATETIME === $lcClass || DateTimeSupport::CLASS_DATETIMEIMMUTABLE === $lcClass) {
                return DateTimeSupport::encodeZendSerializeWire($entry);
            }
            if (DateIntervalSupport::CLASS_DATEINTERVAL === $lcClass) {
                return DateIntervalSupport::encodeZendSerializeWire($entry);
            }
            if (SplArraySerializeSupport::isSplArrayClass($lcClass)) {
                return SplArraySerializeSupport::encodeZendSerializeWire($entry);
            }
            if (self::hasInstanceMethod($entry->class, '__serialize')) {
                $data = self::invokeSerialize($ctx, $entry);
                if (Variable::TYPE_ARRAY !== $data->type) {
                    self::throwSerializeMustReturnArray($entry->class->name);
                }

                return self::encodeMagicSerializeObject($ctx, $entry, $data, null, $frame);
            }
            if (self::implementsLegacySerializable($entry->class)) {
                $payload = self::invokeLegacySerializableSerialize($ctx, $entry);

                return self::encodeSerializableObject($entry->class->name, $payload);
            }
            if (self::hasInstanceMethod($entry->class, '__sleep')) {
                return self::encodeSleepObject($ctx, $entry);
            }

            return self::encodePlainObjectWire($ctx, $entry, $frame);
        }

        if (Variable::TYPE_ARRAY === $value->type) {
            return self::encodeWireArray($ctx, $value, new VmSerializeRefState(), $frame);
        }

        return self::serializeExported(self::exportForSerialize($ctx, $value));
    }

    /** Zend enum wire format: E:len:"EnumName:CaseName"; (php-src ext/standard/var.c). */
    public static function encodeEnumCaseLiteral(string $className, string $caseName): string
    {
        $payload = $className.':'.$caseName;
        $len = \strlen($payload);

        return 'E:'.$len.':"'.$payload.'";';
    }

    /**
     * Serialize exported PHP data using VM serialize_precision (php-src var.c / PG(serialize_precision); #7100, #7103).
     */
    public static function serializeExported(mixed $exported): string
    {
        return VmSerializeFormat::encodeExported($exported);
    }

    /**
     * @param array<string, mixed>|null $options unserialize() options (allowed_classes, max_depth; #3300)
     */
    public static function unserializePayload(Context $ctx, string $payload, ?array $options = null, ?Frame $frame = null): mixed
    {
        if (str_starts_with($payload, 'C:')) {
            $parsed = self::parseSerializableObjectPayload($payload);
            if (null === $parsed) {
                return false;
            }
            [$className, $data] = $parsed;
            if (!self::isClassAllowedForUnserialize($className, $options)) {
                return self::instantiateIncompleteObject($ctx, $className, []);
            }
            $class = self::resolveClassEntryForUnserialize($ctx, $className);
            if (null === $class) {
                return false;
            }
            if (!self::implementsLegacySerializable($class)) {
                return false;
            }

            return self::instantiateLegacySerializable($ctx, $class, $data);
        }

        if (str_starts_with($payload, 'E:')) {
            $parsed = self::parseEnumCasePayload($payload);
            if (null === $parsed) {
                return false;
            }
            [$className, $caseName] = $parsed;
            $resolved = self::resolveEnumCaseVariable($ctx, $className, $caseName);
            if (null === $resolved) {
                return false;
            }

            return $resolved;
        }

        if (str_starts_with($payload, 'O:')) {
            $parsed = self::parseCustomObjectPayload($payload);
            if (null === $parsed) {
                return false;
            }
            [$className, $data] = $parsed;
            if (0 === strcasecmp($className, 'Closure')) {
                throw new \Exception("Unserialization of 'Closure' is not allowed");
            }
            if (!self::isClassAllowedForUnserialize($className, $options)) {
                if (!\is_array($data)) {
                    return false;
                }

                return self::instantiateIncompleteObject($ctx, $className, $data);
            }
            $lcClass = strtolower($className);
            if (\is_array($data)
                && (DateTimeSupport::CLASS_DATETIME === $lcClass || DateTimeSupport::CLASS_DATETIMEIMMUTABLE === $lcClass)) {
                $restored = DateTimeSupport::restoreFromZendSerialize($ctx, $lcClass, $data);
                if (null === $restored) {
                    return false;
                }
                $var = new Variable(Variable::TYPE_OBJECT);
                $var->object($restored);

                return $var;
            }
            if (\is_array($data) && DateIntervalSupport::CLASS_DATEINTERVAL === $lcClass) {
                $restored = DateIntervalSupport::restoreFromZendSerialize($ctx, $data);
                if (null === $restored) {
                    return false;
                }
                $var = new Variable(Variable::TYPE_OBJECT);
                $var->object($restored);

                return $var;
            }
            if (\is_array($data) && SplArraySerializeSupport::isSplArrayClass($lcClass)) {
                $restored = SplArraySerializeSupport::restoreFromZendSerialize($ctx, $lcClass, $data);
                if (null === $restored) {
                    return false;
                }
                $var = new Variable(Variable::TYPE_OBJECT);
                $var->object($restored);

                return $var;
            }
            $class = self::resolveClassEntryForUnserialize($ctx, $className);
            if (null === $class) {
                if (!\is_array($data)) {
                    return false;
                }

                return self::instantiateIncompleteObject($ctx, $className, $data);
            }
            if (self::hasInstanceMethod($class, '__unserialize')) {
                if (!\is_array($data)) {
                    return false;
                }

                return self::instantiateWithUnserialize($ctx, $class, $data);
            }
            if (self::hasInstanceMethod($class, '__wakeup')) {
                if (!\is_array($data)) {
                    return false;
                }

                return self::instantiateWithWakeup($ctx, $class, $data, $frame);
            }
            if (!\is_array($data)) {
                return false;
            }
            if ($class->isInterface || $class->isTrait || $class->isEnum || $class->isAbstract) {
                return false;
            }

            if (\preg_match('/^O:(\d+):"((?:[^"\\\\]|\\\\.)*)":(\d+):\{(.*)\}$/s', $payload, $m)) {
                $inner = $m[4];
                $propCount = (int) $m[3];

                return VmUnserializeFormat::decodeObjectPropertyBag($ctx, $class, $propCount, $inner, $frame);
            }

            return self::instantiatePlainObject($ctx, $class, $data, $frame);
        }

        return VmUnserializeFormat::decodeToVariableWithContext($ctx, $payload, $options, $frame);
    }

    /**
     * @param array<string, mixed>|null $options
     */
    public static function isClassAllowedForUnserialize(string $className, ?array $options): bool
    {
        if (null === $options || !\array_key_exists('allowed_classes', $options)) {
            return true;
        }
        $allowed = $options['allowed_classes'];
        if (false === $allowed) {
            return false;
        }
        if (true === $allowed) {
            return true;
        }
        if (!\is_array($allowed)) {
            throw new \LogicException('allowed_classes must be of type bool or array');
        }
        foreach ($allowed as $entry) {
            if (\is_string($entry) && 0 === \strcasecmp($entry, $className)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function encodeCustomObject(string $className, array $data): string
    {
        $inner = VmSerializeFormat::encodeExported($data);
        if (!str_starts_with($inner, 'a:')) {
            throw new \LogicException('serialize() failed');
        }
        $len = \strlen($className);

        return 'O:'.$len.':"'.$className.'":'.\substr($inner, 2);
    }

    /**
     * __serialize() wire encoding with object reference markers (php-src ext/standard/var.c, #11903).
     */
    private static function encodeMagicSerializeObject(
        Context $ctx,
        ObjectEntry $entry,
        Variable $data,
        ?VmSerializeRefState $state = null,
        ?Frame $frame = null
    ): string {
        $isRoot = null === $state;
        if ($isRoot) {
            $state = new VmSerializeRefState();
            $state->reserveRootSlot();
        }

        $body = '';
        $count = 0;
        foreach ($data->toArray()->iterateKeyed(true) as [$key, $value]) {
            $body .= self::encodeWireKey($key);
            $body .= self::encodeWireVariable($ctx, $value, $state, $frame);
            ++$count;
        }
        $className = $entry->class->name;
        $classLen = \strlen($className);

        return 'O:'.$classLen.':"'.$className.'":'.$count.':{'.$body.'}';
    }

    private static function encodeWireKey(Variable $key): string
    {
        $key = $key->resolveIndirect();
        if (Variable::TYPE_STRING === $key->type) {
            return VmSerializeFormat::encodeStringLiteral($key->toString());
        }
        if (Variable::TYPE_INTEGER === $key->type) {
            return 'i:'.$key->toInt().';';
        }

        throw new \LogicException(
            'serialize() only supports string or integer keys in this compiler build'
        );
    }

    private static function encodeWireVariable(
        Context $ctx,
        Variable $value,
        VmSerializeRefState $state,
        ?Frame $frame = null,
        ?Variable $containerArray = null
    ): string {
        if ($value->isIndirect()) {
            $existingRef = $state->lookupRefCellIndex($value);
            if (null !== $existingRef) {
                return 'R:'.$existingRef.';';
            }
            $target = $value->resolveIndirect();
            $targetExisting = $state->lookupVariableIndex($target);
            if (null !== $targetExisting
                && null !== $containerArray
                && Variable::TYPE_ARRAY === $target->type
                && $target->resolveIndirect() === $containerArray->resolveIndirect()
            ) {
                $state->assignRefCellIndex($value);

                return self::encodeWireArray($ctx, $target, $state, $frame, $containerArray);
            }
            if (null !== $targetExisting) {
                $state->assignRefCellIndex($value, $targetExisting);

                return 'R:'.$targetExisting.';';
            }
            $refIndex = $state->assignRefCellIndex($value);
            $state->assignVariableIndexWithIndex($target, $refIndex);
            $value = $target;
        } else {
            $value = $value->resolveIndirect();
            if (Variable::TYPE_OBJECT !== $value->type) {
                $existing = $state->lookupVariableIndex($value);
                if (null !== $existing) {
                    return 'R:'.$existing.';';
                }
                $state->assignVariableIndex($value);
            }
        }
        $resourceWire = self::serializeResourceWire($value);
        if (null !== $resourceWire) {
            return $resourceWire;
        }
        $enumRef = self::enumCaseRefFromVariable($value);
        if (null !== $enumRef) {
            return self::encodeEnumCaseLiteral($enumRef->className, $enumRef->caseName);
        }
        if (Variable::TYPE_OBJECT === $value->type) {
            return self::encodeWireObject($ctx, $value->toObject(), $state, $frame);
        }
        if (Variable::TYPE_ARRAY === $value->type) {
            return self::encodeWireArray($ctx, $value, $state, $frame);
        }

        return VmSerializeFormat::encodeExported(VmJson::export($value, $ctx, $ctx->runtime->vm));
    }

    private static function encodeWireArray(
        Context $ctx,
        Variable $value,
        VmSerializeRefState $state,
        ?Frame $frame = null,
        ?Variable $containerArray = null
    ): string {
        $value = $value->resolveIndirect();
        if (null === $state->lookupVariableIndex($value)) {
            $state->assignVariableIndex($value);
        }
        $body = '';
        $count = 0;
        foreach ($value->toArray()->iterateKeyed(false) as [$key, $elem]) {
            $body .= self::encodeWireKey($key);
            $body .= self::encodeWireVariable($ctx, $elem, $state, $frame, $value);
            ++$count;
        }

        return 'a:'.$count.':{'.$body.'}';
    }

    private static function encodeWireObject(
        Context $ctx,
        ObjectEntry $entry,
        VmSerializeRefState $state,
        ?Frame $frame = null
    ): string {
        if (0 === strcasecmp($entry->class->name, 'Closure')) {
            throw new \Exception("Serialization of 'Closure' is not allowed");
        }
        $existing = $state->lookupObjectIndex($entry);
        if (null !== $existing) {
            return 'r:'.$existing.';';
        }
        $state->assignObjectIndex($entry);

        $lcClass = strtolower($entry->class->name);
        if (DateTimeSupport::CLASS_DATETIME === $lcClass || DateTimeSupport::CLASS_DATETIMEIMMUTABLE === $lcClass) {
            return DateTimeSupport::encodeZendSerializeWire($entry);
        }
        if (DateIntervalSupport::CLASS_DATEINTERVAL === $lcClass) {
            return DateIntervalSupport::encodeZendSerializeWire($entry);
        }
        if (SplArraySerializeSupport::isSplArrayClass($lcClass)) {
            return SplArraySerializeSupport::encodeZendSerializeWire($entry);
        }
        if (self::hasInstanceMethod($entry->class, '__serialize')) {
            $magicData = self::invokeSerialize($ctx, $entry);
            if (Variable::TYPE_ARRAY !== $magicData->type) {
                self::throwSerializeMustReturnArray($entry->class->name);
            }

            return self::encodeMagicSerializeObject($ctx, $entry, $magicData, $state, $frame);
        }
        if (self::implementsLegacySerializable($entry->class)) {
            $payload = self::invokeLegacySerializableSerialize($ctx, $entry);

            return self::encodeSerializableObject($entry->class->name, $payload);
        }
        if (self::hasInstanceMethod($entry->class, '__sleep')) {
            return self::encodeSleepObject($ctx, $entry);
        }

        return self::encodePlainObjectWire($ctx, $entry, $frame, $state);
    }

    /** Zend Serializable custom object format: C:len:"Class":datalen:{payload} */
    public static function encodeSerializableObject(string $className, string $payload): string
    {
        $classLen = \strlen($className);
        $dataLen = \strlen($payload);

        return 'C:'.$classLen.':"'.$className.'":'.$dataLen.':{'.$payload.'}';
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    public static function parseCustomObjectPayload(string $payload): ?array
    {
        if (!\preg_match('/^O:(\d+):"((?:[^"\\\\]|\\\\.)*)":(\d+):\{(.*)\}$/s', $payload, $m)) {
            return null;
        }
        $declaredLen = (int) $m[1];
        $className = stripcslashes($m[2]);
        if (\strlen($className) !== $declaredLen) {
            return null;
        }
        $arrayPayload = 'a:'.$m[3].':{'.$m[4].'}';
        $data = VmUnserializeFormat::decodePayload($arrayPayload);
        if (false === $data || !\is_array($data)) {
            return null;
        }

        return [$className, $data];
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    public static function parseSerializableObjectPayload(string $payload): ?array
    {
        if (!\preg_match('/^C:(\d+):"((?:[^"\\\\]|\\\\.)*)":(\d+):\{(.+)\}$/s', $payload, $m)) {
            return null;
        }
        $declaredLen = (int) $m[1];
        $className = stripcslashes($m[2]);
        if (\strlen($className) !== $declaredLen) {
            return null;
        }
        $dataLen = (int) $m[3];
        $data = $m[4];
        if (\strlen($data) !== $dataLen) {
            return null;
        }

        return [$className, $data];
    }

    public static function instantiateWithUnserialize(
        Context $ctx,
        ClassEntry $class,
        array $data
    ): Variable {
        $method = $class->methods['__unserialize'] ?? null;
        if (!$method instanceof PhpFunc) {
            throw new \LogicException(
                'Class '.$class->name.'::__unserialize() must be a user method in this compiler build'
            );
        }
        $entry = new ObjectEntry($class);
        $recv = new Variable();
        $recv->object($entry);
        $dataVar = VmJson::import($data);
        $ctx->runtime->vm->invokePhpFunction($method, $recv, $dataVar);

        return $recv;
    }

    /**
     * Zend var_unserializer.c — plain O: object with property bag (no __unserialize/__wakeup; #5140).
     */
    public static function instantiatePlainObject(
        Context $ctx,
        ClassEntry $class,
        array $data,
        ?Frame $frame = null
    ): Variable {
        $entry = new ObjectEntry($class);
        // Zend restores serialized props on a live object; hooks must run (#6474).
        $entry->constructed = true;
        self::restoreObjectProperties($ctx, $entry, $data, $frame);
        $recv = new Variable();
        $recv->object($entry);

        return $recv;
    }

    public static function instantiateWithWakeup(
        Context $ctx,
        ClassEntry $class,
        array $data,
        ?Frame $frame = null
    ): Variable {
        $entry = new ObjectEntry($class);
        self::restoreObjectProperties($ctx, $entry, $data, $frame);
        $method = $class->methods['__wakeup'] ?? null;
        if (!$method instanceof PhpFunc) {
            throw new \LogicException(
                'Class '.$class->name.'::__wakeup() must be a user method in this compiler build'
            );
        }
        $recv = new Variable();
        $recv->object($entry);
        $ctx->runtime->vm->invokePhpFunction($method, $recv);

        return $recv;
    }

    public static function instantiateLegacySerializable(
        Context $ctx,
        ClassEntry $class,
        string $data
    ): Variable {
        $method = $class->methods['unserialize'] ?? null;
        if (!$method instanceof PhpFunc) {
            throw new \LogicException(
                'Class '.$class->name.'::unserialize() must be a user method in this compiler build'
            );
        }
        $entry = new ObjectEntry($class);
        $recv = new Variable();
        $recv->object($entry);
        $dataVar = new Variable();
        $dataVar->string($data);
        $ctx->runtime->vm->invokePhpFunction($method, $recv, $dataVar);

        return $recv;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    public static function parseEnumCasePayload(string $payload): ?array
    {
        if (!\preg_match('/^E:(\d+):"((?:[^"\\\\]|\\\\.)*)";$/', $payload, $m)) {
            return null;
        }
        $declaredLen = (int) $m[1];
        $inner = $m[2];
        if (\strlen($inner) !== $declaredLen) {
            return null;
        }
        $unescaped = self::unescapeSerializedEnumPayload($inner);
        $colonPos = strrpos($unescaped, ':');
        if (false === $colonPos || 0 === $colonPos) {
            return null;
        }
        $className = \substr($unescaped, 0, $colonPos);
        $caseName = \substr($unescaped, $colonPos + 1);
        if ('' === $className || '' === $caseName) {
            return null;
        }

        return [$className, $caseName];
    }

    public static function resolveEnumCaseVariable(
        Context $ctx,
        string $className,
        string $caseName
    ): ?Variable {
        $lc = strtolower($className);
        if (!isset($ctx->classes[$lc])) {
            $ctx->autoloadClass($className);
        }
        if (!isset($ctx->classes[$lc])) {
            return null;
        }
        $enum = $ctx->classes[$lc];
        if (!$enum->isEnum) {
            return null;
        }
        $canonical = BackedEnum::canonicalCaseVariable($enum, $caseName);
        if (null === $canonical) {
            return null;
        }
        $resolved = $canonical->resolveIndirect();
        if (Variable::TYPE_ENUM_CASE === $resolved->type
            || (Variable::TYPE_OBJECT === $resolved->type && EnumCaseSupport::isEnumCase($resolved->toObject()))) {
            $var = new Variable();
            $var->copyFrom($resolved);

            return $var;
        }

        return null;
    }

    /**
     * php-src ext/standard/var.c — resource zvals serialize as integer wire (i:N;).
     * PHP 8.4 Resource objects use id 0; closed handles must not leak stale ids (#5326).
     */
    private static function serializeResourceWire(Variable $value): ?string
    {
        if (!ResourceSupport::isVmResource($value)) {
            return null;
        }

        return 'i:0;';
    }

    private static function exportForSerialize(Context $ctx, Variable $value): mixed
    {
        $value = $value->resolveIndirect();
        if (VmClosureCall::isClosure($value)) {
            throw new \Exception("Serialization of 'Closure' is not allowed");
        }
        $enumRef = self::enumCaseRefFromVariable($value);
        if (null !== $enumRef) {
            return $enumRef;
        }
        if (Variable::TYPE_ARRAY === $value->type) {
            $out = [];
            foreach ($value->toArray()->iterateKeyed(true) as [$key, $elem]) {
                $k = $key->resolveIndirect();
                if (Variable::TYPE_STRING === $k->type) {
                    $out[$k->toString()] = self::exportForSerialize($ctx, $elem);
                } elseif (Variable::TYPE_INTEGER === $k->type) {
                    $out[$k->toInt()] = self::exportForSerialize($ctx, $elem);
                } else {
                    throw new \LogicException(
                        'serialize() only supports string or integer keys in this compiler build'
                    );
                }
            }

            return $out;
        }

        return VmJson::export($value, $ctx, $ctx->runtime->vm);
    }

    /** Unescape php-src serialize string escapes in enum E: payloads (var_unserializer.c). */
    private static function unescapeSerializedEnumPayload(string $payload): string
    {
        return \preg_replace_callback(
            '/\\\\([\\\\"nrt])/',
            static function (array $m): string {
                return match ($m[1]) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    default => $m[1],
                };
            },
            $payload
        ) ?? $payload;
    }

    private static function enumCaseRefFromVariable(Variable $value): ?VmSerializeEnumCaseRef
    {
        $value = $value->resolveIndirect();
        if (Variable::TYPE_ENUM_CASE === $value->type) {
            $case = $value->toEnumCase();

            return new VmSerializeEnumCaseRef($case->enumClass->name, $case->caseName);
        }
        if (Variable::TYPE_OBJECT === $value->type && EnumCaseSupport::isEnumCase($value->toObject())) {
            $object = $value->toObject();

            return new VmSerializeEnumCaseRef($object->class->name, $object->enumCaseName ?? '');
        }

        return null;
    }

    private static function encodeSleepObject(Context $ctx, ObjectEntry $entry): string
    {
        $names = self::invokeSleep($ctx, $entry);
        $props = [];
        foreach ($names as $name) {
            $props[$name] = $entry->getProperty($name)->resolveIndirect();
        }

        return self::encodeObjectPropertyBag($ctx, $entry->class->name, $props);
    }

    /**
     * Zend plain object wire with object-reference markers (ext/standard/var.c, #12082).
     */
    private static function encodePlainObjectWire(
        Context $ctx,
        ObjectEntry $entry,
        ?Frame $frame = null,
        ?VmSerializeRefState $state = null
    ): string {
        $isRoot = null === $state;
        if ($isRoot) {
            $state = new VmSerializeRefState();
            $state->objectIndex[$entry] = 1;
            $state->nextIndex = 2;
        } elseif (null === $state->lookupObjectIndex($entry)) {
            $state->assignObjectIndex($entry);
        }

        $body = '';
        $count = 0;
        foreach (self::collectPlainObjectSerializeProperties($ctx, $entry, $frame) as $name => $value) {
            $keyVar = new Variable();
            $keyVar->string($name);
            $body .= self::encodeWireKey($keyVar);
            $body .= self::encodeWireVariable($ctx, $value, $state, $frame);
            ++$count;
        }
        $className = $entry->class->name;
        $classLen = \strlen($className);

        return 'O:'.$classLen.':"'.$className.'":'.$count.':{'.$body.'}';
    }

    /**
     * Zend php_var_serialize() plain object branch — public properties + dynamic props (#3621, var.c).
     * Private/protected mangling deferred to #3497.
     */
    private static function encodePlainObject(Context $ctx, ObjectEntry $entry, ?Frame $frame = null): string
    {
        return self::encodePlainObjectWire($ctx, $entry, $frame);
    }

    /**
     * @return array<string, Variable>
     */
    private static function collectPlainObjectSerializeProperties(
        Context $ctx,
        ObjectEntry $entry,
        ?Frame $frame = null
    ): array {
        if (null !== $frame) {
            return $ctx->runtime->vm()->collectPublicPropertiesForSerialize($entry, $frame);
        }

        /** @var array<string, Variable> $props */
        $props = [];
        /** @var array<string, true> $seenLc */
        $seenLc = [];
        foreach (array_reverse(VmReflection::classHierarchyChain($entry->class, $ctx)) as $class) {
            foreach ($class->properties as $meta) {
                $lc = strtolower($meta->name);
                if (isset($seenLc[$lc])) {
                    continue;
                }
                $seenLc[$lc] = true;
                if (!MethodVisibility::isPublic($meta->visibility)) {
                    continue;
                }
                if (!$entry->hasProperty($meta->name)) {
                    continue;
                }
                $value = $entry->getProperty($meta->name)->resolveIndirect();
                if (TypedPropertyCheck::omitFromPropertyEnumeration($value)) {
                    continue;
                }
                $copy = new Variable();
                $copy->copyFrom($value);
                $props[$meta->name] = $copy;
            }
        }
        foreach ($entry->getRawProperties() as $name => $prop) {
            if (isset($seenLc[strtolower($name)])) {
                continue;
            }
            $value = $prop->resolveIndirect();
            if (TypedPropertyCheck::omitFromPropertyEnumeration($value)) {
                continue;
            }
            $copy = new Variable();
            $copy->copyFrom($value);
            $props[$name] = $copy;
        }

        return $props;
    }

    /**
     * @param array<string, Variable> $props
     */
    private static function encodeObjectPropertyBag(Context $ctx, string $className, array $props): string
    {
        $body = '';
        foreach ($props as $name => $value) {
            $body .= self::encodeSerializedScalar($name);
            $body .= self::encodeSerializedValue($ctx, $value);
        }
        $count = \count($props);
        $classLen = \strlen($className);

        return 'O:'.$classLen.':"'.$className.'":'.$count.':{'.$body.'}';
    }

    private static function encodeSerializedValue(Context $ctx, Variable $value): string
    {
        return self::encodeSerializedScalar(self::exportForSerialize($ctx, $value));
    }

    /** Zend object wire with named exported properties (DateTime, DateInterval, #10710, #10692). */
    public static function encodeExportedPropertyBag(string $className, array $exportedProps): string
    {
        $body = '';
        foreach ($exportedProps as $name => $exported) {
            $body .= self::encodeSerializedScalar($name);
            $body .= self::encodeSerializedScalar($exported);
        }
        $count = \count($exportedProps);
        $classLen = \strlen($className);

        return 'O:'.$classLen.':"'.$className.'":'.$count.':{'.$body.'}';
    }

    /**
     * Zend object wire with integer property keys (ArrayObject, ArrayIterator; spl_array.c #10711).
     *
     * @param array<int, mixed> $exportedProps
     */
    public static function encodeIntegerKeyedPropertyBag(string $className, array $exportedProps): string
    {
        $body = '';
        ksort($exportedProps);
        foreach ($exportedProps as $key => $exported) {
            $body .= 'i:'.(int) $key.';';
            $body .= self::encodeSerializedScalar($exported);
        }
        $count = \count($exportedProps);
        $classLen = \strlen($className);

        return 'O:'.$classLen.':"'.$className.'":'.$count.':{'.$body.'}';
    }

    private static function encodeSerializedScalar(mixed $exported): string
    {
        return self::serializeExported($exported);
    }

    /** @param array<string, mixed> $data */
    private static function restoreObjectProperties(
        Context $ctx,
        ObjectEntry $entry,
        array $data,
        ?Frame $frame = null
    ): void {
        $vm = $ctx->runtime->vm();
        foreach ($data as $name => $raw) {
            $propName = (string) $name;
            $value = VmJson::import($raw);
            if (null !== $frame) {
                $vm->assignUnserializeProperty($entry, $propName, $value, $frame);
                continue;
            }
            $prop = $entry->hasProperty($propName)
                ? $entry->getProperty($propName)
                : $entry->allocateProperty($propName);
            $prop->copyFrom($value);
        }
    }

    /**
     * Resolve class for O:/C: unserialize after autoload + unserialize_callback_func (var_unserializer.c, #6564).
     */
    private static function resolveClassEntryForUnserialize(Context $ctx, string $className): ?ClassEntry
    {
        $lc = strtolower($className);
        if (isset($ctx->classes[$lc])) {
            return $ctx->classes[$lc];
        }
        $ctx->autoloadClass($className);
        if (isset($ctx->classes[$lc])) {
            return $ctx->classes[$lc];
        }
        $callback = VmIni::getUnserializeCallbackFunc();
        if ('' === $callback) {
            return null;
        }
        $classNameVar = new Variable();
        $classNameVar->string($className);
        $result = self::invokeNamedFunction($ctx, $callback, $classNameVar);
        if (!$result->resolveIndirect()->toBool()) {
            $ctx->errors->triggerError(
                "unserialize(): Function {$callback}() hasn't defined the class it was called for",
                ErrorReporter::E_WARNING
            );
        }
        if (isset($ctx->classes[$lc])) {
            return $ctx->classes[$lc];
        }

        return null;
    }

    /**
     * Zend __PHP_Incomplete_Class placeholder when class definition is missing (var_unserializer.c, #6564).
     *
     * @param array<string, mixed> $data
     */
    public static function instantiateIncompleteObject(
        Context $ctx,
        string $missingClassName,
        array $data
    ): Variable {
        $icClass = $ctx->classes['__php_incomplete_class'] ?? null;
        if (null === $icClass) {
            throw new \LogicException('__PHP_Incomplete_Class is not registered in this compiler build');
        }
        $entry = new ObjectEntry($icClass);
        $nameProp = $entry->allocateProperty('__PHP_Incomplete_Class_Name');
        $nameProp->string($missingClassName);
        self::restoreObjectProperties($ctx, $entry, $data, null);
        $recv = new Variable();
        $recv->object($entry);

        return $recv;
    }

    private static function invokeNamedFunction(Context $ctx, string $name, Variable ...$args): Variable
    {
        if (str_contains($name, '::')) {
            throw new \LogicException(
                'Static method unserialize callbacks are not supported in this compiler build'
            );
        }
        $lc = strtolower($name);
        if (!isset($ctx->functions[$lc])) {
            throw new \LogicException("Function {$name}() is not defined");
        }
        $fn = $ctx->functions[$lc];
        if ($fn instanceof FuncInternal) {
            $frame = new Frame($fn, null, null);
            $frame->vmContext = $ctx;
            $frame->calledArgs = $args;
            $out = new Variable();
            $frame->returnVar = $out;
            $fn->execute($frame);

            return $out;
        }
        if ($fn instanceof PhpFunc) {
            return $ctx->runtime->vm->invokePhpFunction($fn, ...$args);
        }

        throw new \LogicException("Function {$name}() is not callable");
    }

    private static function invokeSerialize(Context $ctx, ObjectEntry $entry): Variable
    {
        $method = $entry->class->methods['__serialize'] ?? null;
        if (!$method instanceof PhpFunc) {
            throw new \LogicException(
                'Class '.$entry->class->name.'::__serialize() must be a user method in this compiler build'
            );
        }
        $recv = new Variable();
        $recv->object($entry);
        $result = $ctx->runtime->vm->invokePhpFunction($method, $recv);
        if (Variable::TYPE_ARRAY !== $result->type) {
            self::throwSerializeMustReturnArray($entry->class->name);
        }

        return $result;
    }

    /** @return list<string> */
    private static function invokeSleep(Context $ctx, ObjectEntry $entry): array
    {
        $method = $entry->class->methods['__sleep'] ?? null;
        if (!$method instanceof PhpFunc) {
            throw new \LogicException(
                'Class '.$entry->class->name.'::__sleep() must be a user method in this compiler build'
            );
        }
        $recv = new Variable();
        $recv->object($entry);
        $result = $ctx->runtime->vm->invokePhpFunction($method, $recv);
        if (Variable::TYPE_ARRAY !== $result->type) {
            throw new \LogicException('__sleep() must return an array');
        }
        $names = [];
        foreach ($result->toArray()->iterateKeyed(true) as [, $elem]) {
            $elem = $elem->resolveIndirect();
            if (Variable::TYPE_STRING !== $elem->type) {
                throw new \LogicException('__sleep() must return an array of strings');
            }
            $names[] = $elem->toString();
        }

        return $names;
    }

    private static function invokeLegacySerializableSerialize(Context $ctx, ObjectEntry $entry): string
    {
        $method = $entry->class->methods['serialize'] ?? null;
        if (!$method instanceof PhpFunc) {
            throw new \LogicException(
                'Class '.$entry->class->name.'::serialize() must be a user method in this compiler build'
            );
        }
        $recv = new Variable();
        $recv->object($entry);
        $result = $ctx->runtime->vm->invokePhpFunction($method, $recv);
        if (Variable::TYPE_STRING !== $result->type) {
            throw new \LogicException('Serializable::serialize() must return a string');
        }

        return $result->toString();
    }

    private static function implementsLegacySerializable(ClassEntry $class): bool
    {
        if (!\in_array('serializable', $class->interfaces, true)) {
            return false;
        }

        return isset($class->methods['serialize'], $class->methods['unserialize']);
    }

    private static function hasInstanceMethod(ClassEntry $class, string $methodName): bool
    {
        return isset($class->methods[strtolower($methodName)]);
    }

    /** php-src zend_class_serialize() — __serialize() must return array (TypeError). */
    private static function throwSerializeMustReturnArray(string $className): never
    {
        throw new \TypeError($className.'::__serialize() must return an array');
    }
}

/**
 * Object reference indices for serialize() wire format (php-src var.c php_add_var_hash).
 *
 * Root __serialize O: occupies stream index 1; nested object refs start at 2 (#11903).
 */
final class VmSerializeRefState
{
    public int $nextIndex = 1;

    /** @var \SplObjectStorage<ObjectEntry, int> */
    public \SplObjectStorage $objectIndex;

    /** @var \SplObjectStorage<Variable, int> */
    public \SplObjectStorage $variableIndex;

    /** @var \SplObjectStorage<Variable, int> */
    public \SplObjectStorage $refCellIndex;

    public function __construct()
    {
        $this->objectIndex = new \SplObjectStorage();
        $this->variableIndex = new \SplObjectStorage();
        $this->refCellIndex = new \SplObjectStorage();
    }

    public function reserveRootSlot(): void
    {
        $this->nextIndex = 2;
    }

    public function assignObjectIndex(ObjectEntry $object): int
    {
        $index = $this->nextIndex++;
        $this->objectIndex[$object] = $index;

        return $index;
    }

    public function lookupObjectIndex(ObjectEntry $object): ?int
    {
        if (!$this->objectIndex->contains($object)) {
            return null;
        }

        return $this->objectIndex[$object];
    }

    /** php-src var_hash — shared scalar/array refs emit R: (var.c php_var_serialize). */
    public function assignVariableIndex(Variable $variable): int
    {
        $index = $this->nextIndex++;
        $this->variableIndex[$variable] = $index;

        return $index;
    }

    public function lookupVariableIndex(Variable $variable): ?int
    {
        if (!$this->variableIndex->contains($variable)) {
            return null;
        }

        return $this->variableIndex[$variable];
    }

    /** php-src ISREF zval identity — R: markers keyed by ref cell, not target (#12825). */
    public function assignRefCellIndex(Variable $refCell, ?int $index = null): int
    {
        if (null !== $index) {
            $this->refCellIndex[$refCell] = $index;

            return $index;
        }
        $index = $this->nextIndex++;
        $this->refCellIndex[$refCell] = $index;

        return $index;
    }

    public function lookupRefCellIndex(Variable $refCell): ?int
    {
        if (!$this->refCellIndex->contains($refCell)) {
            return null;
        }

        return $this->refCellIndex[$refCell];
    }

    public function assignVariableIndexWithIndex(Variable $variable, int $index): void
    {
        $this->variableIndex[$variable] = $index;
        if ($index >= $this->nextIndex) {
            $this->nextIndex = $index + 1;
        }
    }
}

/** Marker for enum case values in VmSerialize::exportForSerialize() (#6131). */
final class VmSerializeEnumCaseRef
{
    public function __construct(
        public readonly string $className,
        public readonly string $caseName,
    ) {
    }
}
