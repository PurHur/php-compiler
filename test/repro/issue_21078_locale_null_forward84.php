<?php
// Repro #21078 — locale_* $locale null TypeError on PROFILE=8.4 (Z_PARAM_STR)
foreach ([
    'primary' => static fn () => locale_get_primary_language(null),
    'region' => static fn () => locale_get_region(null),
    'script' => static fn () => locale_get_script(null),
    'canonicalize' => static fn () => locale_canonicalize(null),
    'display_name' => static fn () => locale_get_display_name(null),
    'method_primary' => static fn () => Locale::getPrimaryLanguage(null),
    'method_canonicalize' => static fn () => Locale::canonicalize(null),
    'method_display' => static fn () => Locale::getDisplayName(null),
] as $name => $call) {
    try {
        $r = $call();
        echo $name, ' COERCED ', var_export($r, true), "\n";
    } catch (TypeError $e) {
        echo $name, ' TypeError';
        if (false !== strpos($e->getMessage(), 'null given')
            || false !== strpos($e->getMessage(), 'must be of type string')) {
            echo ' null';
        }
        echo "\n";
    }
}
echo 'ok_primary=', var_export(locale_get_primary_language('en_US'), true), "\n";
echo 'ok_region=', var_export(locale_get_region('en_US'), true), "\n";
