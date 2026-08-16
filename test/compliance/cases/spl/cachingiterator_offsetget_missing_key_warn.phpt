--TEST--
CachingIterator FULL_CACHE offsetGet missing key → Undefined array key Warning (#31576)
--FILE--
<?php
error_reporting(E_ALL);
$warn = null;
set_error_handler(static function (int $n, string $m) use (&$warn): bool {
    $warn = $m;

    return true;
});

$c = new CachingIterator(new ArrayIterator(['a' => 1]), CachingIterator::FULL_CACHE);
foreach ($c as $_) {
}
$warn = null;
$v = $c->offsetGet('x');
echo 'method_value=', var_export($v, true), "\n";
echo 'method_warn=', $warn === null ? 'NULL' : $warn, "\n";

$warn = null;
$v2 = $c['x'];
echo 'dim_value=', var_export($v2, true), "\n";
echo 'dim_warn=', $warn === null ? 'NULL' : $warn, "\n";

$warn = null;
$present = $c->offsetGet('a');
echo 'present_value=', var_export($present, true), "\n";
echo 'present_warn=', $warn === null ? 'NULL' : $warn, "\n";
?>
--EXPECT--
method_value=NULL
method_warn=Undefined array key "x"
dim_value=NULL
dim_warn=Undefined array key "x"
present_value=1
present_warn=NULL
