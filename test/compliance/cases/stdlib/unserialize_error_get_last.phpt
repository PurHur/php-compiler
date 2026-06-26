--TEST--
stdlib unserialize() invalid payload — error_get_last after @ (issue #9206)
--FILE--
<?php
$payload = 'not a serialize';
$len = strlen($payload);
var_dump(@unserialize($payload));
$last = error_get_last();
echo is_array($last) ? 'set' : 'null', "\n";
echo str_contains($last['message'] ?? '', 'Error at offset 0') ? 'offset0' : 'badmsg', "\n";
echo ($last['type'] ?? 0) === 8 ? 'notice' : 'badtype', "\n";
error_reporting(0);
var_dump(unserialize($payload));
$last2 = error_get_last();
echo str_contains($last2['message'] ?? '', "of {$len} bytes") ? 'lenok' : 'badlen', "\n";
--EXPECT--
bool(false)
set
offset0
notice
bool(false)
lenok
