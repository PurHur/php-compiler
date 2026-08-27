<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;

/**
 * DOMDocument validate/schemaValidate/relaxNG/xinclude/registerNodeClass NestedJIT (#35540).
 *
 * Returns int 0/1 for bool methods; xinclude returns count or -1 for false.
 */
final class DomDocumentValidateJitHelper
{
    public static function validateArgv(Context $ctx, ObjectEntry $document): int
    {
        return VmDom::validate($ctx, $document, null) ? 1 : 0;
    }

    public static function schemaValidateArgv(
        Context $ctx,
        ObjectEntry $document,
        string $filename,
        int $flags
    ): int {
        return VmDom::schemaValidate($ctx, $document, $filename, $flags, null) ? 1 : 0;
    }

    public static function schemaValidateSourceArgv(
        Context $ctx,
        ObjectEntry $document,
        string $source,
        int $flags
    ): int {
        return VmDom::schemaValidateSource($ctx, $document, $source, $flags, null) ? 1 : 0;
    }

    public static function relaxNGValidateArgv(
        Context $ctx,
        ObjectEntry $document,
        string $filename
    ): int {
        return VmDom::relaxNGValidate($ctx, $document, $filename, null) ? 1 : 0;
    }

    public static function relaxNGValidateSourceArgv(
        Context $ctx,
        ObjectEntry $document,
        string $source
    ): int {
        return VmDom::relaxNGValidateSource($ctx, $document, $source, null) ? 1 : 0;
    }

    /** @return int substitutions, or -1 when php-src returns false */
    public static function xincludeArgv(Context $ctx, ObjectEntry $document, int $options): int
    {
        $count = VmDom::xinclude($ctx, $document, $options, null);
        // NestedJIT collapses false to non-int; map non-positive to -1 (php-src false).
        $n = is_int($count) ? $count : 0;
        return $n > 0 ? $n : -1;
    }

    public static function registerNodeClassArgv(
        Context $ctx,
        ObjectEntry $document,
        string $baseClass,
        string $extendedClass
    ): int {
        $extended = '' === $extendedClass ? null : $extendedClass;
        VmDom::registerNodeClass($ctx, $document, $baseClass, $extended);

        return 1;
    }
}
