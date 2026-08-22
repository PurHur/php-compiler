<?php
// Repro #33711 — AOT http_build_query on runtime arrays (not call-site literals).
$a = [1, 2];
echo http_build_query($a), PHP_EOL;
$b = [1];
$b[] = null;
$b[] = 2;
echo http_build_query($b), PHP_EOL;
