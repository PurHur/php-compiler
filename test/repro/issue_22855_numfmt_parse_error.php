<?php
/**
 * Repro #22855 — NumberFormatter::parse failure → U_PARSE_ERROR (9), global intl error stays 0.
 */
$f = new NumberFormatter('en_US', NumberFormatter::DECIMAL);
$r = $f->parse('not-a-number');
var_export($r);
echo "\n";
echo $f->getErrorCode(), "\n";
echo $f->getErrorMessage(), "\n";
echo intl_get_error_code(), "\n";
