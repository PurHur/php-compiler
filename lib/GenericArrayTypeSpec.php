<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * PHP 8.3+ generic array type metadata (list&lt;T&gt;, array&lt;K,V&gt;) via source rewrite (#3705).
 *
 * Declarations are rewritten to magic identifier types (__phpc_list__int) before parse;
 * this value object is recovered in the compiler and enforced in the VM.
 */
final class GenericArrayTypeSpec
{
    public const KIND_LIST = 'list';
    public const KIND_ARRAY = 'array';

    private const PREFIX_LIST = '__phpc_list__';
    private const PREFIX_ARRAY = '__phpc_array__';

    public function __construct(
        public readonly string $kind,
        public readonly ?string $valueType,
        public readonly ?string $keyType = null,
    ) {
    }

    public static function encodeList(string $valueType = 'mixed'): string
    {
        return self::PREFIX_LIST . self::normalizeTypeName($valueType);
    }

    public static function encodeArray(string $keyType, string $valueType): string
    {
        return self::PREFIX_ARRAY . self::normalizeTypeName($keyType) . '__' . self::normalizeTypeName($valueType);
    }

    public static function tryParseDeclName(string $declName): ?self
    {
        if (str_starts_with($declName, self::PREFIX_LIST)) {
            $inner = substr($declName, strlen(self::PREFIX_LIST));

            return new self(self::KIND_LIST, '' !== $inner ? $inner : 'mixed');
        }
        if (str_starts_with($declName, self::PREFIX_ARRAY)) {
            $inner = substr($declName, strlen(self::PREFIX_ARRAY));
            $parts = explode('__', $inner, 2);
            if (2 !== count($parts)) {
                return new self(self::KIND_ARRAY, 'mixed', 'mixed');
            }

            return new self(self::KIND_ARRAY, $parts[1], $parts[0]);
        }

        return null;
    }

    private static function normalizeTypeName(string $type): string
    {
        $type = strtolower(trim($type));
        if ('' === $type) {
            return 'mixed';
        }

        return preg_replace('/[^a-z0-9_\\\\]/', '', $type) ?? 'mixed';
    }
}
