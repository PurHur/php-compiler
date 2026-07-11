<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * link() for compiled JIT/AOT modules (php-in-PHP).
 *
 * SSOT: {@see VmFs::hardLink()}
 * php-src: ext/standard/link.c — php_link
 */
final class LinkJitHelper
{
    public static function invokeArgv(string $target, string $link): bool
    {
        $ok = VmFs::hardLink($target, $link);
        if (!$ok) {
            TriggerErrorJitHelper::warning('link(): No such file or directory');
        }

        return $ok;
    }
}
