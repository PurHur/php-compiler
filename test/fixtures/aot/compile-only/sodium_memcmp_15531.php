<?php
if (!extension_loaded('sodium') || !function_exists('sodium_memcmp')) {
    echo "missing\n";
    exit(0);
}
echo sodium_memcmp('abc', 'abc') === 0 ? "eq\n" : "eq_fail\n";
echo sodium_memcmp('abc', 'abd') !== 0 ? "ne\n" : "ne_fail\n";
