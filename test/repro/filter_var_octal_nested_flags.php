<?php
declare(strict_types=1);

// #22772 — Zend ignores flags nested under options[]; only top-level 'flags' apply.
$nested = filter_var('01', FILTER_VALIDATE_INT, ['options' => ['flags' => FILTER_FLAG_ALLOW_OCTAL]]);
$top = filter_var('01', FILTER_VALIDATE_INT, ['flags' => FILTER_FLAG_ALLOW_OCTAL]);
$none = filter_var('01', FILTER_VALIDATE_INT);

echo false === $nested ? "nested:false\n" : ("nested:" . var_export($nested, true) . "\n");
echo 1 === $top ? "top:1\n" : ("top:" . var_export($top, true) . "\n");
echo false === $none ? "none:false\n" : ("none:" . var_export($none, true) . "\n");
