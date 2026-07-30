<?php
declare(strict_types=1);

// #25508: stream_context_create Reflection must have no return type (Zend resource).
$r = new ReflectionFunction('stream_context_create');
echo 'has_return=', $r->hasReturnType() ? '1' : '0', "\n";
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
$ctx = stream_context_create();
echo 'resource_type=', get_resource_type($ctx), "\n";
