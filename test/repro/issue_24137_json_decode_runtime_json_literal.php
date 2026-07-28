<?php
$j = '{"a":1}';
$r = json_decode($j, true);
echo count($r), ' ', ($r['a'] ?? 'x'), "\n";
