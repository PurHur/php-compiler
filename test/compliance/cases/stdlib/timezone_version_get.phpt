--TEST--
stdlib timezone_version_get() — IANA tzdata version from zoneinfo (#6832, #29386)
--FILE--
<?php
echo function_exists('timezone_version_get') ? "exists\n" : "missing\n";
$version = timezone_version_get();
echo is_string($version) && '' !== $version ? "non_empty\n" : "empty\n";
echo strlen($version) > 0 ? "len_ok\n" : "len_bad\n";
echo '0.system' === $version ? "sentinel\n" : "not_sentinel\n";
--EXPECT--
exists
non_empty
len_ok
not_sentinel
