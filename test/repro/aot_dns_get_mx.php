<?php

declare(strict_types=1);

$hosts = [];
$weights = [];
$ok = dns_get_mx('example.com', $hosts, $weights);
var_dump($ok, $hosts, $weights);
