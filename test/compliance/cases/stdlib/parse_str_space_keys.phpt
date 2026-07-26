--TEST--
stdlib parse_str() maps spaces in root keys to underscores (#23529)
--FILE--
<?php
parse_str('a.b=1&c d=2&e[f]=3&g+h=4&x%20y=5&p[q r]=6', $out);
foreach ($out as $k => $v) {
    echo json_encode($k), '=>', json_encode($v), "\n";
}
--EXPECT--
"a_b"=>"1"
"c_d"=>"2"
"e"=>{"f":"3"}
"g_h"=>"4"
"x_y"=>"5"
"p"=>{"q r":"6"}
