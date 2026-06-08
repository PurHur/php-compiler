--TEST--
stdlib timezone_version_get() — non-empty tzdata version string (#6832)
--FILE--
<?php
echo function_exists('timezone_version_get') ? "exists\n" : "missing\n";
$version = timezone_version_get();
echo is_string($version) && '' !== $version ? "non_empty\n" : "empty\n";
echo strlen($version) > 0 ? "len_ok\n" : "len_bad\n";
--EXPECT--
exists
non_empty
len_ok
