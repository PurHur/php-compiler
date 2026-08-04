--TEST--
AOT realpath_cache_get() returns array (#27665)
--FILE--
<?php
$g = realpath_cache_get();
echo is_array($g) ? ('arr|'.(count($g) >= 0 ? 'ok' : 'bad')) : 'no';
echo "\n";
--EXPECT--
arr|ok
