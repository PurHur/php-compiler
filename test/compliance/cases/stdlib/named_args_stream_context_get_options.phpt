--TEST--
stream_context_get_options Reflection + named stream_or_context (VM, issue #24584)
--FILE--
<?php
$r = new ReflectionFunction('stream_context_get_options');
echo implode(',', array_map(static fn ($p) => $p->getName(), $r->getParameters())), PHP_EOL;
echo 'arity=', $r->getNumberOfParameters(), ' required=', $r->getNumberOfRequiredParameters(), PHP_EOL;
$ctx = stream_context_create(['http' => ['method' => 'GET']]);
$named = stream_context_get_options(stream_or_context: $ctx);
echo $named['http']['method'], PHP_EOL;
$pos = stream_context_get_options($ctx);
echo $pos['http']['method'], PHP_EOL;
try {
    stream_context_get_options(context: $ctx);
    echo "context_ok\n";
} catch (Error $e) {
    echo 'context_err=', $e->getMessage(), PHP_EOL;
}
--EXPECT--
stream_or_context
arity=1 required=1
GET
GET
context_err=Unknown named parameter $context
