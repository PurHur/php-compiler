--TEST--
stdlib preg_replace_callback() limit and count by-ref (issues #4442, #19637)
--FILE--
<?php
$count = 0;
$out = preg_replace_callback('/a/', function ($m) { return 'x'; }, 'aa', 1, $count);
var_dump($out, $count);

$count = 0;
$out = preg_replace_callback('/a/', fn($m) => 'A', 'aa', -1, $count);
echo $out, '|', $count, "\n";

$count = 0;
$out = preg_replace_callback('/a/', fn($m) => 'A', 'aaa', 2, $count);
echo $out, '|', $count, "\n";

$count = 0;
$out = preg_replace_callback('/z/', fn($m) => 'A', 'aa', -1, $count);
echo $out, '|', $count, "\n";
?>
--EXPECT--
string(2) "xa"
int(1)
AA|2
AAa|2
aa|0
