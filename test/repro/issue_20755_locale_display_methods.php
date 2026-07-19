<?php
// Repro #20755 — Locale display/variant APIs (php-src-strict).
$r = new ReflectionClass('Locale');
foreach (['getDisplayLanguage', 'getDisplayRegion', 'getDisplayScript', 'getDisplayVariant', 'getAllVariants'] as $n) {
    echo $n, '=', $r->hasMethod($n) ? '1' : '0', "\n";
}
echo 'lang=', Locale::getDisplayLanguage('en_US', 'en'), "\n";
echo 'region=', Locale::getDisplayRegion('en_US', 'en'), "\n";
echo 'script=', Locale::getDisplayScript('zh_Hans_CN', 'en'), "\n";
echo 'variant=', Locale::getDisplayVariant('en_US_POSIX', 'en'), "\n";
echo 'allv=', json_encode(Locale::getAllVariants('sl_IT_NEDIS_ROJAZ_ALBA')), "\n";
echo 'proc=', locale_get_display_language('fr', 'en'), "\n";
