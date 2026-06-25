<?php

/**
 * Maintainer repro: preg_replace() null $pattern — E_WARNING + null (#11015, ext/pcre/php_pcre.c).
 */

$r = @preg_replace(null, 'x', 'abc');
echo 'result=', var_export($r, true), "\n";
echo 'warning=', (int) ($r === null), "\n";
