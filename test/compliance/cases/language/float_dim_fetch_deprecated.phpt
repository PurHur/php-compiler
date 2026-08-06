--TEST--
language: float array dim read/isset/unset E_DEPRECATED (#27948, zend_execute.c)
--FILE--
<?php
error_reporting(E_ALL);
$seen = [];
set_error_handler(static function (int $no, string $str) use (&$seen): bool {
    $seen[] = [$no, $str];
    return true;
});

$seen = [];
$a = [10, 20, 30];
echo 'read=', $a[1.5], "\n";
echo 'read_depr=', (isset($seen[0]) && E_DEPRECATED === $seen[0][0]) ? '1' : '0', "\n";
echo 'read_msg=', $seen[0][1] ?? '', "\n";

$seen = [];
echo 'isset=', isset($a[1.5]) ? '1' : '0', "\n";
echo 'isset_depr=', (isset($seen[0]) && E_DEPRECATED === $seen[0][0]) ? '1' : '0', "\n";

$seen = [];
unset($a[1.5]);
echo 'unset_count=', count($a), "\n";
echo 'unset_depr=', (isset($seen[0]) && E_DEPRECATED === $seen[0][0]) ? '1' : '0', "\n";

$seen = [];
$b = [];
$b[1.5] = 'x';
echo 'write=', $b[1], "\n";
echo 'write_depr=', (isset($seen[0]) && E_DEPRECATED === $seen[0][0]) ? '1' : '0', "\n";

$seen = [];
$c = ['k' => 'v', 2 => 'two'];
echo 'mix=', $c[2.9], "\n";
echo 'mix_depr=', (isset($seen[0]) && E_DEPRECATED === $seen[0][0]) ? '1' : '0', "\n";

$seen = [];
echo 'integral=', ([10, 20])[1.0], "\n";
echo 'integral_depr=', empty($seen) ? '0' : '1', "\n";
--EXPECT--
read=20
read_depr=1
read_msg=Implicit conversion from float 1.5 to int loses precision
isset=1
isset_depr=1
unset_count=2
unset_depr=1
write=x
write_depr=1
mix=two
mix_depr=1
integral=20
integral_depr=0
