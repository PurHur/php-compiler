<?php
/**
 * #28780 — unixtojd(PHP_INT_MAX) → false; Reflection int|false (php-src cal_unix.c).
 */
$r = new ReflectionFunction('unixtojd');
echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', PHP_EOL;
echo 'max=', var_export(unixtojd(PHP_INT_MAX), true), PHP_EOL;
echo 'ok=', var_export(is_int(unixtojd(0)), true), PHP_EOL;
