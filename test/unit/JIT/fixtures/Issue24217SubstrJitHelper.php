<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * NestedJIT fixture for #24217 — unqualified substr()/strlen() in helper namespace.
 *
 * NsFuncCall qualifies these as phpcompiler\ext\standard\{substr,strlen}; NestedJIT must
 * resolve them to Func\Internal, not Call\ExternalMethod silent-null stubs.
 */
final class Issue24217SubstrJitHelper
{
    public static function sliceArgv(string $s, int $offset, int $length): string
    {
        $len = strlen($s);
        if ($offset < 0 || $offset >= $len) {
            return '';
        }

        return substr($s, $offset, $length);
    }
}
