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

echo "ok\n";
