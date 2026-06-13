--TEST--
stdlib realpath_cache_size() / realpath_cache_get() after realpath() (#3463)
--FILE--
<?php
$path = tempnam(sys_get_temp_dir(), 'phpc_realpath_cache_');
if (!is_string($path)) {
    echo "skip\n";
    return;
}
touch($path);
realpath($path);
echo realpath_cache_size() > 0 ? "size_ok\n" : "size_fail\n";
$cache = realpath_cache_get();
echo isset($cache[$path]) || count($cache) > 0 ? "cache_ok\n" : "cache_empty\n";
foreach ($cache as $entry) {
    echo isset($entry['realpath']) && isset($entry['expires']) ? "entry_ok\n" : "entry_bad\n";
    break;
}
@unlink($path);
--EXPECT--
size_ok
cache_ok
entry_ok
