<?php

declare(strict_types=1);

$xml = simplexml_load_string('<root a="1"/>');
var_export((string) $xml['a']);
echo "\n";
echo var_export((string) $xml['a'], true), "\n";
var_export($xml['a']);
echo "\n";
