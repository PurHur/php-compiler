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
     * InternalArgInfo return fields are often the declaring-class type, not the tentative scalar.
     *
     * @var array<string, array<string, string>>
     */
    private const TENTATIVE_CLASS_METHOD_RETURNS = [
        'datetime' => [
            'format' => 'string',
        ],
        'datetimeimmutable' => [
            'format' => 'string',
        ],
        'datetimeinterface' => [
            'format' => 'string',
        ],
    ];

    private static ?InternalArgInfo $argInfo = null;

    public static function paramCountForFunction(string $name): ?int
    {
        $lc = strtolower($name);
        $info = self::instance()->functions[$lc] ?? null;
        if (null === $info) {
            return null;
        }

        return \count($info['params']);
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

        return self::normalizeParamInfo($info['params'][$index]);
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

    public static function tentativeReturnTypeForClassMethod(string $class, string $method): ?string
    {
        $classLc = strtolower($class);
        $methodLc = strtolower($method);

        return self::TENTATIVE_CLASS_METHOD_RETURNS[$classLc][$methodLc] ?? null;
    }

    public static function typeStringAllowsNull(string $type): bool
    {
        $type = trim($type);
        if ('' === $type) {
            return true;
        }
        if (str_starts_with($type, '?')) {
            return true;
        }
        if (str_contains($type, '|')) {
            foreach (explode('|', $type) as $member) {
                if ('null' === strtolower(trim($member))) {
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
    private static function normalizeParamInfo(array $param): array
    {
        $name = $param['name'];
        $isOptional = str_ends_with($name, '=');
        if ($isOptional) {
            $name = substr($name, 0, -1);
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
