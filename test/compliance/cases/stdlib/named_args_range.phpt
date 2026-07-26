--TEST--
range named start/end/step arguments (VM, issue #23242)
--FILE--
<?php
var_export(range(start: 1, end: 3));
echo PHP_EOL;
var_export(range(start: 1, end: 5, step: 2));
echo PHP_EOL;
$rf = new ReflectionFunction('range');
foreach ($rf->getParameters() as $p) {
    echo $p->getName(), PHP_EOL;
}
try {
    range(low: 1, high: 3);
    echo "low accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
--EXPECT--
array (
  0 => 1,
  1 => 2,
  2 => 3,
)
array (
  0 => 1,
  1 => 3,
  2 => 5,
)
start
end
step
Unknown named parameter $low
