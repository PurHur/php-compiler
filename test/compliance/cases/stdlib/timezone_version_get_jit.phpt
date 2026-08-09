--TEST--
stdlib timezone_version_get() JIT — IANA tzdata version from zoneinfo (#6832, #29386)
--JIT--
--FILE--
<?php
echo function_exists('timezone_version_get') ? "exists\n" : "missing\n";
$version = timezone_version_get();
echo is_string($version) && '' !== $version ? "non_empty\n" : "empty\n";
echo '0.system' === $version ? "sentinel\n" : "not_sentinel\n";
--EXPECT--
exists
non_empty
not_sentinel
