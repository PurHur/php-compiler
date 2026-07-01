--TEST--
stream_resolve_include_path() resolves directories on include_path (#14481, ext/standard/streams.c)
--FILE--
<?php
$inc = getcwd();
$old = set_include_path($inc);
$resolved = stream_resolve_include_path('lib');
set_include_path($old);
echo is_string($resolved) ? "found\n" : "notfound\n";
echo str_ends_with($resolved, '/lib') || str_ends_with($resolved, '\\lib') ? "suffix\n" : "nosuffix\n";
--EXPECT--
found
suffix
--CREDITS--
PurHur/php-compiler issue #14481
