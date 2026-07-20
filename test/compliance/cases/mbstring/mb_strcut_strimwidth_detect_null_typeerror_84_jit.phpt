--TEST--
mb_detect_encoding null soft-null on 8.4 profile (#21516; strcut/strimwidth soft-null #21430)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    $r = mb_detect_encoding(null);
    echo 'mb_detect_encoding: OK '.var_export($r, true)."\n";
} catch (TypeError $e) {
    echo 'mb_detect_encoding: '.$e->getMessage()."\n";
}
echo mb_strcut('abc', 1), "\n";
echo mb_strimwidth('abcdef', 0, 3, '..'), "\n";
--EXPECT--
mb_detect_encoding: OK 'ASCII'
bc
a..
