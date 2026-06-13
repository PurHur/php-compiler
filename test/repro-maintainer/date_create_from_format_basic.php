<?php
$r = date_create_from_format('Y-m-d', '2024-06-05');
echo $r->format('Y-m-d'), "\n";
$p = date_parse('2024-01-15 12:00:00');
echo (string) $p['year'], "\n";
