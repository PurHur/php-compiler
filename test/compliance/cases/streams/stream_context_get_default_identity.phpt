--TEST--
streams stream_context_get_default() === identity (#18556, ext/standard/streams.c)
--FILE--
<?php
var_dump(stream_context_get_default() === stream_context_get_default());
$opts = stream_context_get_options(stream_context_get_default());
var_dump(is_array($opts));

$a = stream_context_create(['http' => ['timeout' => 1]]);
$b = stream_context_create(['http' => ['timeout' => 1]]);
var_dump($a === $b);
var_dump($a === stream_context_get_default());
?>
--EXPECT--
bool(true)
bool(true)
bool(false)
bool(false)
