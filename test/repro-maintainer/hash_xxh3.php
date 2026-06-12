<?php
// Issue #5165 — hash('xxh3'/'xxh128') must match Zend (ext/hash/hash_xxhash.c)
foreach (['a', 'abc', '', 'hello world'] as $s) {
    echo 'xxh3(', json_encode($s), ')=', hash('xxh3', $s), "\n";
    echo 'xxh128(', json_encode($s), ')=', hash('xxh128', $s), "\n";
}
