--TEST--
intl Locale::getPrimaryLanguage()/getDisplayName() partial surface (#6696, ext/intl/locale)
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('intl'), "\n";
echo 'class=', (int) class_exists('Locale', false), "\n";
echo 'method=', (int) method_exists('Locale', 'getDefault'), "\n";

echo 'lang=', Locale::getPrimaryLanguage('en_US'), "\n";
echo 'region=', Locale::getRegion('en_US'), "\n";
echo 'script=', Locale::getScript('zh-Hans-CN'), "\n";
echo 'display=', Locale::getDisplayName('en_US'), "\n";
echo 'display_lang=', Locale::getDisplayName('de'), "\n";

try {
    Locale::getPrimaryLanguage(new stdClass());
    echo "fail\n";
} catch (TypeError $e) {
    echo 'enum_or_obj=', str_contains($e->getMessage(), 'must be of type string') ? 'yes' : 'no', "\n";
}

enum E: string { case A = 'en_US'; }
try {
    Locale::getPrimaryLanguage(E::A);
    echo "fail_enum\n";
} catch (TypeError $e) {
    echo 'enum=', str_contains($e->getMessage(), 'must be of type string') ? 'yes' : 'no', "\n";
}
--EXPECT--
loaded=1
class=1
method=1
lang=en
region=US
script=Hans
display=English (United States)
display_lang=German
enum_or_obj=yes
enum=yes
