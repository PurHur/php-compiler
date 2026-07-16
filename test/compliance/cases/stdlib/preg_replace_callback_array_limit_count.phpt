--TEST--
stdlib preg_replace_callback_array() limit and count by-ref / named (issue #19697)
--FILE--
<?php
$count = 0;
$out = preg_replace_callback_array(['/a/' => fn($m) => 'X'], 'aa', -1, $count);
echo $out, '|', $count, "\n";

$count = 0;
$out = preg_replace_callback_array(['/a/' => fn($m) => 'X'], 'aaa', 2, $count);
echo $out, '|', $count, "\n";

$count = 0;
$out = preg_replace_callback_array(['/a/' => fn($m) => 'X'], 'aa', count: $count);
echo 'named|', $out, '|', $count, "\n";

$count = 0;
$out = preg_replace_callback_array(['/a/' => fn($m) => 'X'], 'aa', limit: -1, count: $count);
echo 'both|', $out, '|', $count, "\n";
?>
--EXPECT--
XX|2
XXa|2
named|XX|2
both|XX|2
