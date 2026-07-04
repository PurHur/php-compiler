<?php

declare(strict_types=1);

var_dump(class_exists('Random\\Engine\\Secure'));
var_dump(class_exists('Random\\Engine\\Xoshiro256StarStar'));
var_dump(class_exists('Random\\Engine\\PcgOneseq128XslRr64'));

$secure = new Random\Engine\Secure();
var_dump(is_string($secure->generate()));
var_dump(strlen($secure->generate()) === 8);

$xoshiro = new Random\Engine\Xoshiro256StarStar(42);
var_dump(is_string($xoshiro->generate()));
var_dump(strlen($xoshiro->generate()) === 8);

$pcg = new Random\Engine\PcgOneseq128XslRr64(42);
var_dump(is_string($pcg->generate()));
var_dump(strlen($pcg->generate()) === 8);

echo "ok\n";
