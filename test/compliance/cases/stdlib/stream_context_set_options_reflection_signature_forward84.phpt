--TEST--
stream_context_set_options Reflection context/options + named args (#25453, basic_functions.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$r = new ReflectionFunction('stream_context_set_options');
echo 'arity=', $r->getNumberOfParameters(),
    ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
foreach ($r->getParameters() as $p) {
    echo $p->getName(),
        ' type=', $p->hasType() ? (string) $p->getType() : '-',
        "\n";
}
$c = stream_context_create();
var_export(stream_context_set_options(context: $c, options: ['http' => ['method' => 'GET']]));
echo "\n";
?>
--EXPECT--
arity=2 ret=bool
context type=-
options type=array
true
