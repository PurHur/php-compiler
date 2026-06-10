--TEST--
stdlib str_replace()/str_ireplace() optional &$count (#4239)
--FILE--
<?php
$c = 0;
echo str_replace('a', 'b', 'aba', $c), ' count=', $c, "\n";

$c = 0;
echo str_ireplace('A', 'b', 'AbA', $c), ' count=', $c, "\n";

$c = 0;
echo str_replace('z', 'x', 'no match', $c), ' count=', $c, "\n";
--EXPECT--
bbb count=2
bbb count=2
no match count=0
