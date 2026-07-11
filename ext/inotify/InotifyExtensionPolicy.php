<?php

declare(strict_types=1);

namespace PHPCompiler\ext\inotify;

/**
 * ext/inotify surface advertisement — php-src ext/inotify/php_inotify.c module registration (#18049).
 *
 * In-tree helpers compile but extension_loaded() / constant buckets match Zend only when
 * libc inotify is available on the host ({@see VmInotify::available()}).
 */
final class InotifyExtensionPolicy
{
    /**
     * Optional ext/inotify is compiled in-tree but withheld from extension_loaded() /
     * get_defined_constants(true) buckets until php-src module startup parity (#18049).
     */
    public static function advertisesExtension(): bool
    {
        return false;
    }
}
