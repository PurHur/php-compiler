<?php
// VM-only Reflection map (#22271) — companion to issue_22271_datetime_format_constants.php.
$rc = new ReflectionClass(DateTime::class);
$consts = $rc->getConstants();
ksort($consts);
echo 'count=', count($consts), PHP_EOL;
echo 'has_atom=', $rc->hasConstant('ATOM') ? 'Y' : 'N', PHP_EOL;
echo 'defined=', defined('DateTime::ATOM') ? 'Y' : 'N', PHP_EOL;
$ri = new ReflectionClass(DateTimeImmutable::class);
echo 'immut_count=', count($ri->getConstants()), PHP_EOL;
