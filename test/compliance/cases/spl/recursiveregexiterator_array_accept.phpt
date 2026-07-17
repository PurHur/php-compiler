--TEST--
RecursiveRegexIterator::accept allows non-empty arrays for RII descent (#20152, ext/spl/spl_iterators.c)
--FILE--
<?php
$it = new RecursiveRegexIterator(
    new RecursiveArrayIterator(['a1', ['b2', 'cc', ['e4']], 'd3', 'xx']),
    '/\d/'
);
$top = [];
foreach ($it as $v) {
    $top[] = is_array($v) ? 'ARR' : $v;
}
echo 'top=', implode(',', $top), "\n";
echo 'RII=', implode(',', iterator_to_array(new RecursiveIteratorIterator($it), false)), "\n";
$plain = new RegexIterator(new ArrayIterator(['a1', ['b2'], 'd3']), '/\d/');
$p = [];
foreach ($plain as $v) {
    $p[] = is_array($v) ? 'ARR' : $v;
}
echo 'plain=', implode(',', $p), "\n";
--EXPECT--
top=a1,ARR,d3
RII=a1,b2,e4,d3
plain=a1,d3
