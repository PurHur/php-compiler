--TEST--
AOT realpath_cache_size() returns int (#27664)
--FILE--
<?php
echo gettype(realpath_cache_size());
echo '|';
echo (string) realpath_cache_size();
echo "\n";
--EXPECT--
integer|0
