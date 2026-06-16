--TEST--
stdlib stream_context_get_default() — resource type parity JIT (#8743)
--JIT--
--FILE--
<?php
$ctx = stream_context_get_default();
echo is_resource($ctx) ? "resource\n" : "not_resource\n";
echo get_debug_type($ctx), "\n";
echo gettype($ctx), "\n";
echo get_resource_type($ctx), "\n";
--EXPECT--
resource
resource (stream-context)
resource
stream-context
