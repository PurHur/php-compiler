--TEST--
MessageFormatter {0,date}/{0,time} ICU styles (#25226)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip MessageFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
date_default_timezone_set('UTC');
$ts = strtotime('2024-07-15 UTC');
$tsTime = strtotime('2024-07-15 15:30:00 UTC');

echo (new MessageFormatter('en_US', '{0,date}'))->format([$ts]), "\n";
echo (new MessageFormatter('en_US', '{0,date,short}'))->format([$ts]), "\n";
echo (new MessageFormatter('en_US', '{0,date,long}'))->format([$ts]), "\n";
echo (new MessageFormatter('en_US', '{0,date,full}'))->format([$ts]), "\n";
echo (new MessageFormatter('en_US', '{0,time,short}'))->format([$tsTime]), "\n";
echo (new MessageFormatter('en_US', '{0,time}'))->format([$tsTime]), "\n";
echo (new MessageFormatter('en_US', '{0,time,long}'))->format([$tsTime]), "\n";
echo MessageFormatter::formatMessage('en_US', '{0,date,medium}', [$ts]), "\n";
?>
--EXPECT--
Jul 15, 2024
7/15/24
July 15, 2024
Monday, July 15, 2024
3:30 PM
3:30:00 PM
3:30:00 PM UTC
Jul 15, 2024
