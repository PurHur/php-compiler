<?php
// #27185 — AOT strstr/stristr must match Zend/VM (nullable NestedJIT was silent false).
// Avoid var_export(string|false) — separate AOT gap (peer #27055).
echo strstr('Hello World', 'World'), PHP_EOL;
echo stristr('Hello World', 'WORLD'), PHP_EOL;
echo strstr('abc-def', '-'), PHP_EOL;
echo strstr('abc-def', '-', true), PHP_EOL;
$miss = strstr('abc', 'z');
echo ($miss === false ? 'false' : 'hit'), PHP_EOL;
