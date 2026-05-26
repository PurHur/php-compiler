<?php

declare(strict_types=1);

/**
 * Self-host AOT: duplicate tokenizer-compat defines must not abort link (#2134).
 *
 * Mirrors compiler_lib_spine_smoke requiring tokenizer-compat before bin/vm.php.
 */

require_once __DIR__.'/../../src/tokenizer-compat.php';
require_once __DIR__.'/../../src/tokenizer-compat.php';

echo "duplicate_tokenizer_const_smoke OK\n";
