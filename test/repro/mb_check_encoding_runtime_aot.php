<?php

declare(strict_types=1);

/**
 * mb_check_encoding() runtime string under AOT (#34254 leftover of #4571).
 * Must not Fatal on JIT\Variable::TYPE_ARRAY; match Zend UTF-8 validity.
 */
$ok = 'a'.'';
$jp = '日'.'';
$bad = "\xC0\x80";
$euro = "\xE2\x82\xAC".'';

echo 'ok=', var_export(mb_check_encoding($ok), true), "\n";
echo 'jp=', var_export(mb_check_encoding($jp), true), "\n";
echo 'bad=', var_export(mb_check_encoding($bad), true), "\n";
echo 'euro=', var_export(mb_check_encoding($euro), true), "\n";
echo 'lit=', var_export(mb_check_encoding('a'), true), "\n";
echo 'none=', var_export(mb_check_encoding(), true), "\n";
