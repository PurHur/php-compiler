--TEST--
stdlib parse_str() bracket nesting and list append (issue #6013)
--FILE--
<?php
$out = [];
parse_str('a=1&b[c]=2&b[d][]=3&b[d][]=4', $out);
echo $out['a'], ':', $out['b']['c'], ':', $out['b']['d'][0], ':', $out['b']['d'][1], "\n";
--EXPECT--
1:2:3:4
