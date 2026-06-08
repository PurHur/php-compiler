--TEST--
stdlib timezone_version_get() JIT — non-empty tzdata version string (#6832)
--JIT--
--FILE--
<?php
echo function_exists('timezone_version_get') ? "exists\n" : "missing\n";
$version = timezone_version_get();
echo is_string($version) && '' !== $version ? "non_empty\n" : "empty\n";
--EXPECT--
exists
non_empty
