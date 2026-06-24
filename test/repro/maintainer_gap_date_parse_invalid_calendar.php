<?php
declare(strict_types=1);
$r = date_parse('2020-13-40');
$ok = 1 === $r['month'] && 1 === $r['day'] && 1 === $r['error_count'];
echo 'overflow=', $ok ? 'ok' : 'fail', "\n";

$r2 = date_parse('not-a-date');
$ok2 = false === $r2['year'] && 4 === $r2['error_count'];
echo 'garbage=', $ok2 ? 'ok' : 'fail', "\n";

$r3 = date_parse('2024-01-01');
$ok3 = 2024 === $r3['year'] && 0 === $r3['error_count'];
echo 'control=', $ok3 ? 'ok' : 'fail', "\n";

exit ($ok && $ok2 && $ok3) ? 0 : 1;
