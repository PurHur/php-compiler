--TEST--
language runtime string ++/-- (increment_string, issue #32435)
--FILE--
<?php
function letters()
{
    return 'a';
}
function nines()
{
    return '9';
}
function zee()
{
    return 'z';
}

$s = letters();
$s++;
echo $s, "\n";

$n = nines();
$n++;
var_dump($n);

$z = zee();
$z++;
echo $z, "\n";

$d = letters();
$d--;
echo $d, "\n";
--EXPECT--
b
int(10)
aa
a
