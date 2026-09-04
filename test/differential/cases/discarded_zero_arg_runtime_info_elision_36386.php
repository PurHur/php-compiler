<?php

declare(strict_types=1);

/**
 * Discarded get_declared_* / get_included_files / php_sapi_name / zend_version
 * must not change observable live results (#36386).
 *
 * php-src: ext/standard/basic_functions.c, ext/standard/info.c, Zend/zend.c
 */

get_declared_classes();
get_declared_interfaces();
get_declared_traits();
get_included_files();
get_required_files();
php_sapi_name();
// zend_version discarded-only here: live AOT with php_sapi_name hits a
// pre-existing StringInfo bridge failure (#13803).

$classes = get_declared_classes();
$sapi = php_sapi_name();
$inc = get_included_files();

echo (is_array($classes) && count($classes) > 0 ? '1' : '0')
    . (is_string($sapi) && '' !== $sapi ? '1' : '0')
    . (is_array($inc) ? '1' : '0'), "\n";
