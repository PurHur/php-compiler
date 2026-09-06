<?php
$parts = parse_url("/hello");
echo "keys=".implode(",", array_keys($parts))."\n";
echo "isset_scheme=".(isset($parts["scheme"]) ? "1" : "0")."\n";
$scheme = isset($parts["scheme"]) ? strtr($parts["scheme"], "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") : "";
echo "scheme=[$scheme]\n";
$user = $parts["user"] ?? "";
echo "user=[$user]\n";
$path = isset($parts["path"]) ? $parts["path"] : "";
echo "path=[$path]\n";
echo "OK\n";
