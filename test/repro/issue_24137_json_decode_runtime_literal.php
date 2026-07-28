<?php
// Runtime literal JSON string assoc decode — AOT guard (#24137)
$j = '{"a":1}';
$r = json_decode($j, true);
echo $r['a'], "\n";
