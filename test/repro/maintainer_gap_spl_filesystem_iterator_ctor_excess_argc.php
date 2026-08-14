<?php

declare(strict_types=1);

/**
 * SPL filesystem iterator constructor excess argc (#31070).
 *
 * php-src: ext/spl/spl_directory.c
 */
function show(string $label, callable $fn): void
{
    try {
        $fn();
        echo $label, ": ACCEPTED\n";
    } catch (ArgumentCountError $e) {
        echo $label, ': ', $e->getMessage(), "\n";
    }
}

show('GlobIterator', static fn () => new GlobIterator('*', 0, 1));
show('RecursiveDirectoryIterator', static fn () => new RecursiveDirectoryIterator('.', 0, 1));
show('FilesystemIterator', static fn () => new FilesystemIterator('.', 0, 1));
show('DirectoryIterator', static fn () => new DirectoryIterator('.', 1));
