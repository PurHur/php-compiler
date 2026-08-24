<?php
declare(strict_types=1);

// #34389 — AOT mb_ereg_replace()/mb_eregi_replace() with runtime args (leftover of #33765/#33656).
$p = 'a';
$r = 'x';
$s = 'aAa';
echo mb_ereg_replace($p, $r, $s), PHP_EOL;
echo mb_eregi_replace($p, $r, $s), PHP_EOL;
$world = 'World';
echo mb_ereg_replace($world, 'Earth', 'Hello World'), PHP_EOL;
