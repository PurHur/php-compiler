--TEST--
stdlib preg_split() splits on PCRE pattern (issue #1178)
--FILE--
<?php
$parts = preg_split('/\s+/', 'one two  three');
echo count($parts), "\n";
echo $parts[0], '|', $parts[1], '|', $parts[2], "\n";
$lines = preg_split("/\r?\n/", "a\nb\nc");
echo count($lines), "\n";
echo $lines[0], '|', $lines[1], '|', $lines[2], "\n";
$bad = preg_split('(bad[', 'x');
echo $bad === false ? 'false' : 'bad', "\n";
--EXPECT--
3
one|two|three
3
a|b|c
false
