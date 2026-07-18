<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM;

/**
 * hash extension module entry (php-src ext/hash/hash.c; issue #6937, #7174, #14975).
 *
 * Incremental HashContext lifecycle via VmHashContext + VmHashNative (PHP-in-PHP).
 */
class Module extends ModuleAbstract
{
    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        BuiltinClasses::register($runtime->vmContext);
        foreach (MhashRegistry::constants() as $name => $value) {
            $var = new VM\Variable();
            $var->int($value);
            $runtime->vmContext->defineConstant($name, $var);
        }
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
            new phpc_hash_algos_kernel(),
            new phpc_hash_hmac_algos_kernel(),
            new hash_file(),
            new hash_hmac_file(),
            new mhash(),
            new mhash_count(),
            new mhash_get_hash_name(),
            new mhash_get_block_size(),
            new mhash_keygen_s2k(),
        ];
    }
}
