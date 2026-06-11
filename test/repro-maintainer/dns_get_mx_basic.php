<?php

declare(strict_types=1);

$hosts = [];
$weights = [];
$ok = dns_get_mx('example.com', $hosts, $weights);
var_export($ok);
echo "\n", json_encode($hosts), "\n", json_encode($weights), "\n";

$h = [];
$w = [];
var_export(dns_get_mx('', $h, $w));
echo "\n", json_encode($h), "\n", json_encode($w), "\n";
