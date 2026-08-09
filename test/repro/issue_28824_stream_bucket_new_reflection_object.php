<?php
/**
 * #28824 — stream_bucket_new Reflection return object on default (≤8.3) profile.
 * Run with PHP_COMPILER_PROFILE unset.
 */
$r = new ReflectionFunction('stream_bucket_new');
echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', PHP_EOL;
