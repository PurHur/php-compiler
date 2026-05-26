--TEST--
AOT: stream_context_create() with http options (#2457)
--FILE--
<?php
$ctx = stream_context_create(['http' => ['timeout' => 3]]);
echo $ctx['http']['timeout'], "\n";
--EXPECT--
3
