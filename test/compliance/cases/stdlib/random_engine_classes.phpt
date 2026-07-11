--TEST--
stdlib Random\Engine\Secure/Xoshiro256StarStar/PcgOneseq128XslRr64 generate() 8-byte string (#11550, #15718)
--FILE--
<?php
declare(strict_types=1);

var_dump(class_exists('Random\\Engine\\Secure'));
var_dump(class_exists('Random\\Engine\\Xoshiro256StarStar'));
var_dump(class_exists('Random\\Engine\\PcgOneseq128XslRr64'));

$secure = new Random\Engine\Secure();
$g = $secure->generate();
var_dump(is_string($g));
var_dump(strlen($g) === 8);

$xoshiro = new Random\Engine\Xoshiro256StarStar(42);
$x = $xoshiro->generate();
var_dump(is_string($x));
var_dump(bin2hex($x));

$pcg = new Random\Engine\PcgOneseq128XslRr64(42);
$p = $pcg->generate();
var_dump(is_string($p));
var_dump(bin2hex($p));
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
string(16) "16c72e0c2e0b7815"
bool(true)
string(16) "5a70f57fe8727428"
