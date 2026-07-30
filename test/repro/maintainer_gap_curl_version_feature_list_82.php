<?php
// Issue #25357 — curl_version() must omit feature_list on default/8.2 profile
$v = curl_version();
echo array_key_exists('feature_list', $v) ? "has\n" : "missing\n";
echo PHP_VERSION, "\n";
