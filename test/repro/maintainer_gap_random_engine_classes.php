<?php

declare(strict_types=1);

var_dump(class_exists('Random\\Engine\\Secure'));
var_dump(class_exists('Random\\Engine\\Xoshiro256StarStar'));
var_dump(class_exists('Random\\Engine\\PcgOneseq128XslRr64'));

$secure = new Random\Engine\Secure();
var_dump(is_int($secure->generate()));

$xoshiro = new Random\Engine\Xoshiro256StarStar(42);
var_dump(is_int($xoshiro->generate()));

$pcg = new Random\Engine\PcgOneseq128XslRr64(42);
var_dump(is_int($pcg->generate()));

echo "ok\n";
