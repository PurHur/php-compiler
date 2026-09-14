<?php

declare(strict_types=1);

namespace PHPCompiler\ext\inotify;

use PHPCompiler\ExtensionRegistry;

/**
 * ext/inotify surface advertisement — php-src ext/inotify/php_inotify.c optional module (#18049).
 *
 * {@see advertisesExtension()} is folded to ext.json advertise → ExtensionRegistry (#36204).
 *
 * Pure PHP {@see VmInotify} may compile in-tree but is withheld from extension_loaded(),
 * function_exists(), and get_defined_constants(true) module buckets until the reference
 * Zend build ships ext/inotify (php-src-strict parity on hosts without inotify).
 */
final class InotifyExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return ExtensionRegistry::advertisesExtensionFor('inotify');
    }
}
