--TEST--
stdlib array_filter named mode with omitted/null callback (#24843, ext/standard/array.stub.php)
--FILE--
<?php
$a = [0, 1, '', 'x', false, true, null];
var_export(array_filter($a, mode: ARRAY_FILTER_USE_KEY));
echo "\n";
var_export(array_filter(array: $a, mode: ARRAY_FILTER_USE_BOTH));
echo "\n";
var_export(array_filter($a, null, ARRAY_FILTER_USE_KEY));
echo "\n";
$r = new ReflectionFunction('array_filter');
echo 'required=', $r->getNumberOfRequiredParameters(), ' argc=', $r->getNumberOfParameters(), "\n";
foreach ($r->getParameters() as $p) {
    echo $p->getName();
    if ($p->hasType()) {
        echo ':', $p->getType();
    }
    echo $p->isOptional() ? ' OPT' : ' REQ';
    if ($p->isOptional() && $p->isDefaultValueAvailable()) {
        echo '=', var_export($p->getDefaultValue(), true);
    }
    echo "\n";
}
?>
--EXPECT--
array (
  1 => 1,
  3 => 'x',
  5 => true,
)
array (
  1 => 1,
  3 => 'x',
  5 => true,
)
array (
  1 => 1,
  3 => 'x',
  5 => true,
)
required=1 argc=3
array:array REQ
callback:?callable OPT=NULL
mode:int OPT=0
