--TEST--
Stdlib: stream_context_create() empty context (JIT, #1377, #1056)
--FILE--
<?php
$ctx = stream_context_create();
echo is_array($ctx) ? 'array' : 'other', "\n";
--EXPECT--
array
