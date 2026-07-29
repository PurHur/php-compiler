<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPTypes\InternalArgInfo;

/**
 * php-src internal function arginfo arity (ext/* arginfo tables via ircmaxell/php-types).
 *
 * Used when {@see BuiltinParamNames} has no explicit entry (#11453, ext/reflection/php_reflection.c).
 */
final class BuiltinInternalArgInfo
{
    /**
     * php-src ZEND_TYPE_IS_TENTATIVE return labels (ext/reflection/php_reflection.c, #18226).
     *
     * Delegates to {@see BuiltinInternalTentativeReturnInfo} (Zend 8.2 snapshot).
     */
    public static function tentativeReturnTypeForClassMethod(string $class, string $method): ?string
    {
        return BuiltinInternalTentativeReturnInfo::tentativeReturnTypeLabelForClassMethod($class, $method);
    }

    /**
     * Stub return type label for an internal free function (php-types arginfo `return`).
     *
     * Empty / missing labels mean no declared return type (ext/reflection/php_reflection.c, #22068).
     */
    public static function returnTypeLabelForFunction(string $name): ?string
    {
        $lc = strtolower($name);
        $info = self::instance()->functions[$lc] ?? null;
        if (null === $info) {
            return null;
        }
        $ret = $info['return'] ?? '';
        if (!\is_string($ret)) {
            return null;
        }
        $ret = trim($ret);
        if ('' === $ret) {
            return null;
        }

        return $ret;
    }

    public static function paramCountForFunction(string $name): ?int
    {
        $lc = strtolower($name);
        $info = self::instance()->functions[$lc] ?? null;
        if (null === $info) {
            return null;
        }

        return \count($info['params']);
    }

    public static function paramCountForClassMethod(string $class, string $method): ?int
    {
        $classLc = strtolower($class);
        $methodLc = strtolower($method);
        $methods = self::instance()->methods[$classLc]['methods'] ?? [];
        $info = $methods[$methodLc] ?? null;
        if (null === $info) {
            return null;
        }

        return \count($info['params']);
    }

    public static function requiredParamCountForFunction(string $name): ?int
    {
        $lc = strtolower($name);
        $info = self::instance()->functions[$lc] ?? null;
        if (null === $info) {
            return null;
        }

        return self::requiredParamCountFromRawParams($info['params']);
    }

    public static function requiredParamCountForClassMethod(string $class, string $method): ?int
    {
        $classLc = strtolower($class);
        $methodLc = strtolower($method);
        $methods = self::instance()->methods[$classLc]['methods'] ?? [];
        $info = $methods[$methodLc] ?? null;
        if (null === $info) {
            return null;
        }

        return self::requiredParamCountFromRawParams($info['params']);
    }

    /**
     * @return list<string>
     */
    public static function paramNamesForClassMethod(string $class, string $method): array
    {
        $classLc = strtolower($class);
        $methodLc = strtolower($method);
        $methods = self::instance()->methods[$classLc]['methods'] ?? [];
        $info = $methods[$methodLc] ?? null;
        if (null === $info) {
            return [];
        }
        $names = [];
        foreach ($info['params'] as $param) {
            $names[] = self::normalizeParamInfo($param)['name'];
        }

        return $names;
    }

    /**
     * @return array<string, array{total: int, required: int}>
     */
    public static function functionArityTables(): array
    {
        $tables = [];
        foreach (self::instance()->functions as $funcLc => $info) {
            $tables[$funcLc] = [
                'total' => \count($info['params']),
                'required' => self::requiredParamCountFromRawParams($info['params']),
            ];
        }

        return $tables;
    }

    /**
     * @return array<string, array<string, array{total: int, required: int}>>
     */
    public static function methodArityTables(): array
    {
        $tables = [];
        foreach (self::instance()->methods as $classLc => $classInfo) {
            foreach ($classInfo['methods'] ?? [] as $methodLc => $info) {
                $tables[$classLc][$methodLc] = [
                    'total' => \count($info['params']),
                    'required' => self::requiredParamCountFromRawParams($info['params']),
                ];
            }
        }

        return $tables;
    }

    /**
     * @return array{name: string, type: string, isOptional: bool}|null
     */
    public static function paramInfoForFunction(string $name, int $index): ?array
    {
        $lc = strtolower($name);
        $info = self::instance()->functions[$lc] ?? null;
        if (null === $info || !isset($info['params'][$index])) {
            return null;
        }

        $normalized = self::normalizeParamInfo($info['params'][$index]);
        $typeOverride = self::stubParamTypeOverride($lc, $index);
        if (null !== $typeOverride) {
            $normalized['type'] = $typeOverride;
        }

        return $normalized;
    }

    /**
     * php-src stub types when InternalArgInfo omits nullability (#24845).
     */
    public static function stubParamTypeOverride(string $callableLc, int $index): ?string
    {
        return match ($callableLc) {
            // ext/date/php_date.stub.php — ?int $timestamp / $baseTimestamp = null
            'date', 'gmdate' => 1 === $index ? '?int' : null,
            'strtotime' => 1 === $index ? '?int' : null,
            default => null,
        };
    }

    /**
     * @return array{name: string, type: string, isOptional: bool}|null
     */
    public static function paramInfoForClassMethod(string $class, string $method, int $index): ?array
    {
        $classLc = strtolower($class);
        $methodLc = strtolower($method);
        $methods = self::instance()->methods[$classLc]['methods'] ?? [];
        $info = $methods[$methodLc] ?? null;
        if (null === $info || !isset($info['params'][$index])) {
            return null;
        }

        return self::normalizeParamInfo($info['params'][$index]);
    }

    public static function methodIsVariadic(string $class, string $method): bool
    {
        $classLc = strtolower($class);
        $methodLc = strtolower($method);
        $methods = self::instance()->methods[$classLc]['methods'] ?? [];
        $info = $methods[$methodLc] ?? null;
        if (null === $info) {
            return false;
        }
        foreach ($info['params'] ?? [] as $param) {
            $name = $param['name'] ?? '';
            if (str_starts_with($name, '...')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Index of the `...$name` param in InternalArgInfo, or null (#22825).
     *
     * Note: legacy arginfo may keep a pre-variadic optional (sprintf arg1= + ...=);
     * {@see BuiltinParamNames::variadicParamIndexForFunction()} prefers Zend stub arity.
     */
    public static function variadicParamIndexForFunction(string $name): ?int
    {
        $lc = strtolower($name);
        $info = self::instance()->functions[$lc] ?? null;
        if (null === $info) {
            return null;
        }
        foreach ($info['params'] as $index => $param) {
            $paramName = (string) ($param['name'] ?? '');
            if (str_ends_with($paramName, '=')) {
                $paramName = substr($paramName, 0, -1);
            }
            if (str_starts_with($paramName, '&')) {
                $paramName = substr($paramName, 1);
            }
            if (str_starts_with($paramName, '...')) {
                return $index;
            }
        }

        return null;
    }

    private static ?InternalArgInfo $argInfo = null;

    public static function typeStringAllowsNull(string $type): bool
    {
        $type = trim($type);
        if ('' === $type) {
            return true;
        }
        if (str_starts_with($type, '?')) {
            return true;
        }
        // Explicit `mixed` includes null (php-src ReflectionNamedType::allowsNull).
        if ('mixed' === strtolower($type)) {
            return true;
        }
        if (str_contains($type, '|')) {
            foreach (explode('|', $type) as $member) {
                $member = strtolower(trim($member));
                if ('null' === $member || 'mixed' === $member) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function typeStringAllowsPassByValueWithByRef(string $type): bool
    {
        $type = trim($type);
        if ('' === $type || 'mixed' === strtolower($type)) {
            return true;
        }
        if (str_starts_with($type, '?')) {
            $type = substr($type, 1);
        }
        foreach (explode('|', $type) as $member) {
            $member = trim($member);
            if ('null' === strtolower($member)) {
                continue;
            }
            if (!self::isScalarInternalTypeName($member)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{name: string, type: string} $param
     *
     * @return array{name: string, type: string, isOptional: bool}
     */
    /**
     * @param list<array{name: string, type: string}> $params
     */
    private static function requiredParamCountFromRawParams(array $params): int
    {
        $required = 0;
        foreach ($params as $param) {
            $name = $param['name'] ?? '';
            if (str_ends_with($name, '=') || str_starts_with($name, '...')) {
                break;
            }
            ++$required;
        }

        return $required;
    }

    private static function normalizeParamInfo(array $param): array
    {
        $name = $param['name'];
        $isOptional = str_ends_with($name, '=');
        if ($isOptional) {
            $name = substr($name, 0, -1);
        }
        if (str_starts_with($name, '...')) {
            $name = substr($name, 3);
            $isOptional = true;
        }

        return [
            'name' => $name,
            'type' => $param['type'],
            'isOptional' => $isOptional,
        ];
    }

    private static function isScalarInternalTypeName(string $name): bool
    {
        return \in_array(strtolower($name), [
            'int', 'float', 'string', 'bool', 'array', 'callable', 'iterable',
            'resource', 'void', 'never', 'true', 'false', 'object', 'mixed',
            'self', 'parent', 'static',
        ], true);
    }

    private static function instance(): InternalArgInfo
    {
        return self::$argInfo ??= new InternalArgInfo();
    }
}
