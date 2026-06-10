<?php

declare(strict_types=1);

/**
 * Maintainer repro: var_dump()/print_r() on closed stream (#5149).
 *
 * Zend: resource(N) of type (Unknown) / Resource id #N
 * VM (before fix): object(Resource) / Resource Object
 */
$h = fopen('php://memory', 'r+');
fclose($h);
ob_start();
var_dump($h);
echo ob_get_clean();
echo 'print_r: ', print_r($h, true), "\n";
