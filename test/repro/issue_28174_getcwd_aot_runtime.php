<?php
/**
 * #28174 — getcwd AOT runtime still returns string on success (dir.c).
 * Reflection metadata is exercised on VM; this guards the string|false-capable
 * success path under native AOT (peer #28425 AOT runtime probe shape).
 */
$cwd = getcwd();
echo 'cwd_ok=', is_string($cwd) && $cwd !== '' ? '1' : '0', "\n";
