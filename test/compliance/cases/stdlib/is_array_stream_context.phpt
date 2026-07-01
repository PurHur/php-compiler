--TEST--
stdlib is_array() — stream-context resource is not an array (#14631, ext/standard/type.c)
--FILE--
<?php
$ctx = stream_context_get_default();
var_dump(is_resource($ctx));
var_dump(is_array($ctx));
var_dump(is_array([]));
--EXPECT--
bool(true)
bool(false)
bool(true)
