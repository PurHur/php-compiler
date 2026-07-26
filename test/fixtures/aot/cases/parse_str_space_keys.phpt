--TEST--
AOT parse_str() maps spaces in root keys to underscores (#23529)
--FILE--
<?php
parse_str('a.b=1&c d=2&g+h=4&x%20y=5', $out);
echo $out['a_b'], ' ', $out['c_d'], ' ', $out['g_h'], ' ', $out['x_y'], "\n";
echo isset($out['c d']) ? 'bad-space' : 'no-space', "\n";
--EXPECT--
1 2 4 5
no-space
