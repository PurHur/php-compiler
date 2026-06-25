<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Lazy ghost/proxy object header field names for VM + JIT lowering (#10267).
 *
 * VM state: {@see LazyObjectSupport} on {@see ObjectEntry}; JIT mirrors these on {@code __object__}.
 * php-src: Zend/zend_lazy_objects.c
 */
final class VmLazyObject
{
    public const FIELD_LAZY_PENDING = 'lazy_pending';

    public const FIELD_LAZY_GHOST = 'lazy_ghost';

    public const FIELD_LAZY_INIT_INDEX = 'lazy_init_index';

    public const FIELD_CONSTRUCTED = 'constructed';

    public const FIELD_CLASS_ID = 'class_id';

    /** @return list<string> */
    public static function objectHeaderLazyFields(): array
    {
        return [
            self::FIELD_LAZY_PENDING,
            self::FIELD_LAZY_GHOST,
            self::FIELD_LAZY_INIT_INDEX,
            self::FIELD_CONSTRUCTED,
        ];
    }
}
