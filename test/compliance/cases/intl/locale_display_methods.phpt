--TEST--
Locale getDisplayLanguage/Region/Script/Variant + getAllVariants (#20755)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsLocaleCompliance(basename(__FILE__))) {
    echo 'skip Locale display helpers withheld until extension_loaded(\'intl\') (#19670/#20755)';
}
?>
--FILE--
<?php
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
?>
--EXPECT--
getDisplayLanguage=1
getDisplayRegion=1
getDisplayScript=1
getDisplayVariant=1
getAllVariants=1
lang=English
region=United States
script=Simplified Han
variant=Computer
allv=["NEDIS","ROJAZ","ALBA"]
proc=French
