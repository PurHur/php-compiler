--TEST--
stdlib stream_context_create Reflection has no return type (#25508)
--FILE--
<?php
$r = new ReflectionFunction('stream_context_create');
echo 'has_return=', $r->hasReturnType() ? '1' : '0', "\n";
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
$ctx = stream_context_create();
echo 'resource_type=', get_resource_type($ctx), "\n";
?>
--EXPECT--
has_return=0
return=(none)
resource_type=stream-context
