<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

use PHPCompiler\ModuleAbstract;

/**
 * hash extension module entry (php-src ext/hash/hash.c; issue #6937).
 *
 * Incremental HashContext lifecycle in #3357; one-shot hash() remains in ext/standard.
 */
class Module extends ModuleAbstract
{
    public function getFunctions(): array
    {
        return [
            new hash_init(),
            new hash_update(),
            new hash_final(),
            new hash_copy(),
            new hash_algos(),
        ];
    }
}
