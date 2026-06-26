--TEST--
stream_resolve_include_path() "." and "" resolve to getcwd() (issue #11856)
--FILE--
<?php
$cwd = getcwd();
if (false === $cwd) {
    echo "skip\n";
    exit(0);
}
$dot = stream_resolve_include_path('.');
$empty = stream_resolve_include_path('');
echo $dot === $cwd ? "dot\n" : "dotbad\n";
echo $empty === $cwd ? "empty\n" : "emptybad\n";
--EXPECT--
dot
empty
--CREDITS--
PurHur/php-compiler issue #11856
