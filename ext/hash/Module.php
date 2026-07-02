<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * hash extension module entry (php-src ext/hash/hash.c; issue #6937, #7174).
 *
 * Incremental HashContext lifecycle via VmHashContext + VmHashNative (PHP-in-PHP).
 */
class Module extends ModuleAbstract
{
    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        BuiltinClasses::register($runtime->vmContext);
    }

    public function getFunctions(): array
    {
        return [
            new hash_init(),
            new hash_update(),
            new hash_update_file(),
            new hash_update_stream(),
            new hash_final(),
            new hash_copy(),
            new hash_algos(),
            new hash_file(),
            new hash_hmac_file(),
        ];
    }
}
