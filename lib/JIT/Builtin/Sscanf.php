<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link for __compiler_sscanf* (issue #7330, #9134, #12467, #13149).
 *
 * Array return, by-ref assignment, and vfscanf use {@see SscanfJitHelper} /
 * {@see VfscanfJitHelper} PHP on JIT embed and standalone AOT.
 * php-src: ext/standard/formatted_io.c
 */
final class Sscanf
{
    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        // parseToArray NestedJIT next to live StreamIo mistypes HT returns (#27663).
        if (!$context->isThinStandaloneAotMain()) {
            StringSscanfArray::implement($context);
        }
        StringSscanfByRef::implement($context);
        StringVfscanf::implement($context);
    }
}
