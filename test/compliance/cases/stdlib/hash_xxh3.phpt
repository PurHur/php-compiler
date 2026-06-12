--TEST--
stdlib hash() xxh3 / xxh128 digests (#5165, ext/hash/hash_xxhash.c)
--FILE--
<?php
foreach (['a', 'abc', '', 'hello world'] as $s) {
    echo 'xxh3(', json_encode($s), ')=', hash('xxh3', $s), "\n";
    echo 'xxh128(', json_encode($s), ')=', hash('xxh128', $s), "\n";
}
?>
--EXPECT--
xxh3("a")=e6c632b61e964e1f
xxh128("a")=a96faf705af16834e6c632b61e964e1f
xxh3("abc")=78af5f94892f3950
xxh128("abc")=06b05ab6733a618578af5f94892f3950
xxh3("")=2d06800538d394c2
xxh128("")=99aa06d3014798d86001c324468d497f
xxh3("hello world")=d447b1ea40e6988b
xxh128("hello world")=df8d09e93f874900a99b8775cc15b6c7
