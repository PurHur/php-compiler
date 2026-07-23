--TEST--
date: var_dump DateTime subclass keeps user props + Zend wire (#22462)
--FILE--
<?php
declare(strict_types=1);

date_default_timezone_set('UTC');

class MyDT22462 extends DateTime
{
    public int $x = 1;
    private string $y = 'hid';
}

$o = new MyDT22462('2020-01-01');
ob_start();
var_dump($o);
$out = (string) ob_get_clean();
echo str_contains($out, '__dt_') ? "LEAK\n" : "noleak\n";
echo str_contains($out, '["x"]') ? "has_x\n" : "no_x\n";
echo str_contains($out, '["y"') ? "has_y\n" : "no_y\n";
echo str_contains($out, '["date"]') ? "has_date\n" : "no_date\n";
?>
--EXPECT--
noleak
has_x
has_y
has_date
