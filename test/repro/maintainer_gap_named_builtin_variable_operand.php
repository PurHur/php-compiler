<?php

declare(strict_types=1);

/**
 * Maintainer repro: named builtin calls with variable operands (#12123).
 *
 * php-src: Zend/zend_compile.c — named-arg resolution for internal calls.
 */

$x = 'aca';
$out = str_replace(search: 'a', replace: 'b', subject: $x);
if ('bcb' !== $out) {
    echo "fail: str_replace named with variable subject: {$out}\n";
    exit(1);
}

$utc = new DateTimeZone('UTC');
$immutable = new DateTimeImmutable(datetime: '2020-03-04', timezone: $utc);
if (!($immutable instanceof DateTimeImmutable) || '2020-03-04' !== $immutable->format('Y-m-d')) {
    echo "fail: DateTimeImmutable constructor named with variable timezone\n";
    exit(1);
}

echo "ok\n";
