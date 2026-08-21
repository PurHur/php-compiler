<?php
$r = @strptime('2000-01-02 03:04:05', '%Y-%m-%d %H:%M:%S');
var_export(is_array($r) ? ['tm_year' => $r['tm_year'] ?? null, 'tm_mon' => $r['tm_mon'] ?? null, 'tm_mday' => $r['tm_mday'] ?? null] : $r);
echo "\n";
