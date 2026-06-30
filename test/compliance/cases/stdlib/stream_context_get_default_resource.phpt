--TEST--
stdlib stream_context_get_default() — resource(stream-context) not internal array (#10376, ext/standard/streams.c)
--FILE--
<?php
declare(strict_types=1);
stream_context_set_default(['http' => ['timeout' => 5]]);
$ctx = stream_context_get_default();
echo is_resource($ctx) ? 'resource' : gettype($ctx), "\n";
echo get_resource_type($ctx), "\n";
ob_start();
var_dump($ctx);
$dump = ob_get_clean();
echo str_starts_with($dump, 'resource(') ? 'resource_dump' : 'array_dump', "\n";
--EXPECT--
resource
stream-context
resource_dump
