--TEST--
stdlib Random\IntervalBoundary enum exists with php-src cases (#11551, ext/random/random.stub.php)
--FILE--
<?php
declare(strict_types=1);

var_export(enum_exists('Random\\IntervalBoundary', false));
echo "\n";
var_export(unitenum_exists('Random\\IntervalBoundary'));
echo "\n";
$names = [];
foreach (Random\IntervalBoundary::cases() as $case) {
    $names[] = $case->name;
}
var_export($names);
echo "\n";
var_export(Random\IntervalBoundary::ClosedOpen->name);
echo "\n";
?>
--EXPECT--
true
true
array (
  0 => 'ClosedOpen',
  1 => 'ClosedClosed',
  2 => 'OpenClosed',
  3 => 'OpenOpen',
)
'ClosedOpen'
