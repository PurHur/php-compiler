--TEST--
AOT php_sapi_name() standalone constant bridge (issue #15633)
--FILE--
<?php
echo php_sapi_name() === 'cli' ? "cli\n" : "no\n";
--EXPECT--
cli
