--TEST--
AOT inet_ntop(null)/inet_pton(null) return false (#19053, ext/standard/basic_functions.c)
--FILE--
<?php
echo inet_ntop(null) === false ? 'ntop' : 'no-ntop', "\n";
echo inet_pton(null) === false ? 'pton' : 'no-pton', "\n";
--EXPECT--
ntop
pton
