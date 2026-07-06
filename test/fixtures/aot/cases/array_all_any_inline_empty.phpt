--TEST--
AOT: array_all()/array_any() inline [] haystack (issue #11729)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$cb = fn ($v) => (bool) $v;
echo array_all([], $cb) === true ? "all\n" : "notall\n";
echo array_any([], $cb) === false ? "notany\n" : "any\n";

--EXPECT--
all
notany
