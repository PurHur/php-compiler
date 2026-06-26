<?php

declare(strict_types=1);

$a = [];
$a[0] = &$a;

$output = print_r($a, true);
if (!str_contains($output, '*RECURSION*')) {
    fwrite(STDERR, "missing *RECURSION* marker in output:\n".$output);
    exit(1);
}

$o = new stdClass();
$o->x = &$o;
$objOutput = print_r($o, true);
if (!str_contains($objOutput, '*RECURSION*')) {
    fwrite(STDERR, "missing object *RECURSION* marker in output:\n".$objOutput);
    exit(1);
}

echo "ok\n";
