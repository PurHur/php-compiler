<?php
foreach (['gzcompress', 'gzuncompress', 'gzdeflate', 'gzinflate'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, ' ret=', (string) $r->getReturnType(), "\n";
}
$c = gzcompress('hi');
echo 'round=', gzuncompress($c), "\n";
$d = gzdeflate('hi');
echo 'raw=', gzinflate($d), "\n";
