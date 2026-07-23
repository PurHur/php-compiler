<?php
/** Issue #22623 — IntlDateFormatter::PATTERN on PROFILE=8.4 (UDAT_PATTERN=-2). */
$r = new ReflectionClass(IntlDateFormatter::class);
echo $r->hasConstant('PATTERN') ? '1' : '0', PHP_EOL;
if ($r->hasConstant('PATTERN')) {
    echo IntlDateFormatter::PATTERN, PHP_EOL;
    $f = new IntlDateFormatter('en_US', IntlDateFormatter::PATTERN, IntlDateFormatter::PATTERN, 'UTC', null, 'yyyy-MM-dd');
    echo $f->format(1579046400), PHP_EOL;
}
