--TEST--
stdlib filter_var()/filter_input() null filter Warning + false (ext/filter/filter.c, #18943)
--FILE--
<?php
$r = @filter_var('x', null);
echo $r === false ? "false\n" : "bad\n";
$_GET['q'] = 'x';
$r2 = @filter_input(INPUT_GET, 'q', null);
echo $r2 === false ? "false\n" : "bad\n";
--EXPECT--
false
false
