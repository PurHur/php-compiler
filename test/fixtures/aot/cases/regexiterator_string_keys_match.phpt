--TEST--
RegexIterator AOT MATCH preserves string + sparse int keys (#27313)
--FILE--
<?php
$a = new ArrayIterator(['a' => 'foo', 'b' => 'bar', 'c' => 'baz']);
$r = new RegexIterator($a, '/^ba/');
foreach ($r as $k => $v) {
    echo $k, ':', $v, ',';
}
echo "\n";
$packed = new RegexIterator(new ArrayIterator(['foo', 'bar', 'baz']), '/^ba/');
foreach ($packed as $k => $v) {
    echo $k, ':', $v, ',';
}
echo "\n";
--EXPECT--
b:bar,c:baz,
1:bar,2:baz,
