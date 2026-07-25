--TEST--
Locale getDisplayName/Language/Region honor $displayLocale via ICU (#22901)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsLocaleCompliance(basename(__FILE__))) {
    echo 'skip Locale display helpers withheld until extension_loaded(\'intl\') (#19670/#22901)';
}
?>
--FILE--
<?php
declare(strict_types=1);

echo 'fr_name=', Locale::getDisplayName('en_US', 'fr'), "\n";
echo 'fr_lang=', Locale::getDisplayLanguage('en_US', 'fr'), "\n";
echo 'fr_region=', Locale::getDisplayRegion('en_US', 'fr'), "\n";
echo 'en_name=', Locale::getDisplayName('de_DE', 'en'), "\n";
echo 'en_lang=', Locale::getDisplayLanguage('en_US', 'en'), "\n";
echo 'en_region=', Locale::getDisplayRegion('en_US', 'en'), "\n";
$def = Locale::getDisplayName('en_US', null);
echo 'default_nonempty=', ('' !== $def && false !== $def) ? '1' : '0', "\n";
?>
--EXPECT--
fr_name=anglais (États-Unis)
fr_lang=anglais
fr_region=États-Unis
en_name=German (Germany)
en_lang=English
en_region=United States
default_nonempty=1
