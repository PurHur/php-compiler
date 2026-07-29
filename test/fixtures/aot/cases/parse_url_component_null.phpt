--TEST--
AOT parse_url($url, null) soft-null coerce to PHP_URL_SCHEME (#24942; DEP text on VM/JIT)
--FILE--
<?php
// DEP is verified on VM/JIT. Literal null folds to component 0 (PHP_URL_SCHEME) under AOT.
echo parse_url('http://example.com/x', null), "\n";
?>
--EXPECT--
http
