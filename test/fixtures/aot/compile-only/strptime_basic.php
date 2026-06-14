<?php
$r = strptime('2026-05-30', '%Y-%m-%d');
echo $r['tm_mday'], ' ', $r['tm_mon'], ' ', $r['tm_year'], "\n";
echo $r['unparsed'], "\n";
