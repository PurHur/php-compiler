<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Func\PHP as PhpFunc;
use PHPCompiler\MethodVisibility;
use PHPCompiler\VM\BackedEnum;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ObjectEntry;
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
    public static function serializeValue(Context $ctx, Variable $value): string
    {
        $value = $value->resolveIndirect();
        $enumRef = self::enumCaseRefFromVariable($value);
        if (null !== $enumRef) {
            return self::encodeEnumCaseLiteral($enumRef->className, $enumRef->caseName);
        }
        if (Variable::TYPE_OBJECT === $value->type) {
            $entry = $value->toObject();
            if (self::hasInstanceMethod($entry->class, '__serialize')) {
                $data = self::invokeSerialize($ctx, $entry);
                $exported = VmJson::export($data->resolveIndirect());
                if (!\is_array($exported)) {
                    throw new \LogicException('__serialize() must return an array');
                }

                return self::encodeCustomObject($entry->class->name, $exported);
            }
            if (self::implementsLegacySerializable($entry->class)) {
                $payload = self::invokeLegacySerializableSerialize($ctx, $entry);

                return self::encodeSerializableObject($entry->class->name, $payload);
            }
            if (self::hasInstanceMethod($entry->class, '__sleep')) {
                return self::encodeSleepObject($ctx, $entry);
            }

            return self::encodePlainObject($ctx, $entry);
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
     * @param array<string, mixed>|null $options unserialize() options (allowed_classes only; #3300)
     */
    public static function unserializePayload(Context $ctx, string $payload, ?array $options = null): mixed
    {
        if (str_starts_with($payload, 'C:')) {
            $parsed = self::parseSerializableObjectPayload($payload);
            if (null === $parsed) {
                return false;
            }
            [$className, $data] = $parsed;
            $lc = strtolower($className);
            if (!isset($ctx->classes[$lc])) {
                $ctx->autoloadClass($className);
            }
            if (!isset($ctx->classes[$lc])) {
                return false;
            }
            $class = $ctx->classes[$lc];
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
            if (!self::isClassAllowedForUnserialize($className, $options)) {
                return false;
            }
            $lc = strtolower($className);
            if (!isset($ctx->classes[$lc])) {
                $ctx->autoloadClass($className);
            }
            if (!isset($ctx->classes[$lc])) {
                return false;
            }
            $class = $ctx->classes[$lc];
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

                return self::instantiateWithWakeup($ctx, $class, $data);
            }
            if (!\is_array($data)) {
                return false;
            }
            if ($class->isInterface || $class->isTrait || $class->isEnum || $class->isAbstract) {
                return false;
            }

            return self::instantiatePlainObject($class, $data);
        }

        return @\unserialize($payload);
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
        $inner = \serialize($data);
        if (!str_starts_with($inner, 'a:')) {
            throw new \LogicException('serialize() failed');
        }
        $len = \strlen($className);

        return 'O:'.$len.':"'.$className.'":'.\substr($inner, 2);
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
        $data = @\unserialize($arrayPayload);
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
    public static function instantiatePlainObject(ClassEntry $class, array $data): Variable
    {
        $entry = new ObjectEntry($class);
        self::restoreObjectProperties($entry, $data);
        $recv = new Variable();
        $recv->object($entry);

        return $recv;
    }

    public static function instantiateWithWakeup(
        Context $ctx,
        ClassEntry $class,
        array $data
    ): Variable {
        $entry = new ObjectEntry($class);
        self::restoreObjectProperties($entry, $data);
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

    private static function exportForSerialize(Context $ctx, Variable $value): mixed
    {
        $value = $value->resolveIndirect();
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
     * Zend php_var_serialize() plain object branch — public properties + dynamic props (#3621, var.c).
     * Private/protected mangling deferred to #3497.
     */
    private static function encodePlainObject(Context $ctx, ObjectEntry $entry): string
    {
        return self::encodeObjectPropertyBag(
            $ctx,
            $entry->class->name,
            self::collectPlainObjectSerializeProperties($ctx, $entry)
        );
    }

    /**
     * @return array<string, Variable>
     */
    private static function collectPlainObjectSerializeProperties(Context $ctx, ObjectEntry $entry): array
    {
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

    private static function encodeSerializedScalar(mixed $exported): string
    {
        return self::serializeExported($exported);
    }

    /** @param array<string, mixed> $data */
    private static function restoreObjectProperties(ObjectEntry $entry, array $data): void
    {
        foreach ($data as $name => $raw) {
            $propName = (string) $name;
            $prop = $entry->hasProperty($propName)
                ? $entry->getProperty($propName)
                : $entry->allocateProperty($propName);
            $prop->copyFrom(VmJson::import($raw));
        }
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
            throw new \LogicException('__serialize() must return an array');
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
