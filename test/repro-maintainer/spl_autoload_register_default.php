<?php

declare(strict_types=1);

/**
 * Issue #4256 — spl_autoload_register() without callback registers default spl_autoload.
 */

var_dump(spl_autoload_register());
$funcs = spl_autoload_functions();
var_dump($funcs);
var_dump(in_array('spl_autoload', $funcs, true));
