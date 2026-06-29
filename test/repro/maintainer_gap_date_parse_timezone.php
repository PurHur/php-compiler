<?php

declare(strict_types=1);

$r = date_parse('2020-01-01 12:00:00 UTC');
if (2020 !== $r['year'] || 1 !== $r['month'] || 1 !== $r['day'] || 12 !== $r['hour']) {
    echo 'fail: datetime+tz components year='.$r['year'].' tz='.($r['tz_id'] ?? 'null')."\n";
    exit(1);
}
if ('UTC' !== ($r['tz_id'] ?? null) || 0 !== ($r['error_count'] ?? -1)) {
    echo 'fail: tz_id='.var_export($r['tz_id'] ?? null, true).' error_count='.($r['error_count'] ?? 'null')."\n";
    exit(1);
}

$r2 = date_parse('January 1, 2020');
if (2020 !== $r2['year'] || 1 !== $r2['month'] || 1 !== $r2['day'] || 0 !== ($r2['error_count'] ?? -1)) {
    echo 'fail: english date year='.($r2['year'] ?? 'null').' error_count='.($r2['error_count'] ?? 'null')."\n";
    exit(1);
}
if (false !== ($r2['hour'] ?? null)) {
    echo 'fail: english date hour should be false'."\n";
    exit(1);
}

echo "ok\n";
