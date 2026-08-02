<?php
/**
 * #27068 — filter_var(FILTER_VALIDATE_EMAIL) thin AOT must link + match Zend/VM.
 * Prefer echo/json over var_export(string): AOT var_export of strings segfaults on master.
 */
$a = filter_var('a@b.com', FILTER_VALIDATE_EMAIL);
$b = filter_var('not-email', FILTER_VALIDATE_EMAIL);
echo null === $a || false === $a ? 'false' : "'".$a."'", PHP_EOL;
echo null === $b || false === $b ? 'false' : "'".$b."'", PHP_EOL;
