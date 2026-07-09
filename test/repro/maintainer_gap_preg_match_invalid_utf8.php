<?php

declare(strict_types=1);

/** Issue #17503 — preg_match(/u) on invalid UTF-8 (ext/pcre/php_pcre.c). */
$bad = "\xFF";

var_export(preg_match('//u', $bad));
echo "\n";
var_export(preg_last_error());
echo "\n";
