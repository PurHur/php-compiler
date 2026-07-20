<?php

/**
 * Repro for #21113 — NumberFormatter::getPattern() default for DECIMAL.
 */
$f = new NumberFormatter('en_US', NumberFormatter::DECIMAL);
var_export($f->getPattern());
echo PHP_EOL;
var_export(numfmt_get_pattern($f));
echo PHP_EOL;
