--TEST--
MessageFormatter parse/parseMessage + locale/error accessors (#20718)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip MessageFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$r = new ReflectionClass('MessageFormatter');
foreach (['parse', 'parseMessage', 'getLocale', 'getErrorCode', 'getErrorMessage'] as $m) {
    echo $m, '=', $r->hasMethod($m) ? 'yes' : 'MISSING', "\n";
}
$fmt = MessageFormatter::create('en_US', '{0,number}');
$parsed = $fmt->parse('1,234');
echo 'parse_num=', (int) (is_array($parsed) && 1234 === $parsed[0]), "\n";
$static = MessageFormatter::parseMessage('en_US', '{0,number}', '1,234');
echo 'parse_message=', (int) (is_array($static) && 1234 === $static[0]), "\n";
$fmt2 = MessageFormatter::create('en_US', 'Hello {0}');
$hello = $fmt2->parse('Hello world');
echo 'parse_str=', (int) (is_array($hello) && 'world' === $hello[0]), "\n";
echo 'locale=', $fmt->getLocale(), "\n";
echo 'err=', (int) $fmt->getErrorCode(), ' msg=', $fmt->getErrorMessage(), "\n";
?>
--EXPECT--
parse=yes
parseMessage=yes
getLocale=yes
getErrorCode=yes
getErrorMessage=yes
parse_num=1
parse_message=1
parse_str=1
locale=en_US
err=0 msg=U_ZERO_ERROR
