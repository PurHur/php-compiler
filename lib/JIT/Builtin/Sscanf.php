<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link for __compiler_sscanf* (issue #7330, #9134, #12467, #13149, #32935).
 *
 * Owns module-local ABI decls via StringSscanfByRef / StringSscanfArray /
 * StringVfscanf (getNamedFunction first) — Type always-on shells removed (#32935).
 * Array return, by-ref assignment, and vfscanf use {@see SscanfJitHelper} /
 * {@see VfscanfJitHelper} PHP on JIT embed and standalone AOT.
 * php-src: ext/standard/sscanf.c / ext/standard/file.c (fscanf)
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
