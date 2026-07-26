<?php
/**
 * #22214 — mysqli_fetch_column / mysqli_result::fetch_column advertisement.
 *
 * php-src: ext/mysqli/mysqli.stub.php + mysqli_nonapi.c
 */
foreach (['mysqli_connect', 'mysqli_query', 'mysqli_fetch_assoc', 'mysqli_fetch_row', 'mysqli_fetch_column'] as $fn) {
    echo $fn, '=', function_exists($fn) ? 'yes' : 'NO', "\n";
}
echo 'mysqli_result::fetch_column=', method_exists('mysqli_result', 'fetch_column') ? 'yes' : 'NO', "\n";

$rf = new ReflectionFunction('mysqli_fetch_column');
echo 'proc_params=', $rf->getNumberOfParameters(), ' req=', $rf->getNumberOfRequiredParameters();
foreach ($rf->getParameters() as $p) {
    echo ' [', $p->getName(), $p->isOptional() ? '?' : '', ']';
}
echo "\n";

$rm = new ReflectionMethod('mysqli_result', 'fetch_column');
echo 'method_params=', $rm->getNumberOfParameters(), ' req=', $rm->getNumberOfRequiredParameters();
foreach ($rm->getParameters() as $p) {
    echo ' [', $p->getName(), $p->isOptional() ? '?' : '', ']';
}
echo "\n";
