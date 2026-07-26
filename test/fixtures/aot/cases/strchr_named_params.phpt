--TEST--
AOT: strchr named before_needle compiles and runs (#23218)
--FILE--
<?php
// Named bool must not fatal in CallUnpackCompileTime (Value::constInt).
// strstr/strchr AOT string returns are a separate pre-existing gap (strstr.phpt).
strchr(haystack: 'abcdef', needle: 'd', before_needle: true);
echo is_bool(strchr(haystack: 'abcdef', needle: 'zzz')) ? "ok\n" : "fail\n";
--EXPECT--
ok
