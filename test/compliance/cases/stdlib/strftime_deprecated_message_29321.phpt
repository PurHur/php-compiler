--TEST--
stdlib strftime()/gmstrftime() E_DEPRECATED full Zend wording (#29321, ext/standard/datetime.c)
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
$expect = 'Function strftime() is deprecated since 8.1, use IntlDateFormatter::format() instead';
@strftime('%Y', time());
$last = error_get_last();
echo (($last['type'] ?? 0) === E_DEPRECATED) ? 'strftime_type_ok' : 'strftime_type_fail';
echo "\n";
echo (($last['message'] ?? '') === $expect) ? 'strftime_msg_ok' : ('strftime_msg_fail:'.($last['message'] ?? ''));
echo "\n";
$expectG = 'Function gmstrftime() is deprecated since 8.1, use IntlDateFormatter::format() instead';
@gmstrftime('%Y', time());
$last = error_get_last();
echo (($last['type'] ?? 0) === E_DEPRECATED) ? 'gmstrftime_type_ok' : 'gmstrftime_type_fail';
echo "\n";
echo (($last['message'] ?? '') === $expectG) ? 'gmstrftime_msg_ok' : ('gmstrftime_msg_fail:'.($last['message'] ?? ''));
echo "\n";
?>
--EXPECT--
strftime_type_ok
strftime_msg_ok
gmstrftime_type_ok
gmstrftime_msg_ok
