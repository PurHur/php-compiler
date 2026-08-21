<?php

declare(strict_types=1);

// #33486 leftover of #7268 — deprecated GC no-op (matches VM: no type check)
// Do not require openssl_pkey_get_private() first — that builtin is still JIT-unimplemented.
var_export(openssl_free_key(1));
echo "\n";
