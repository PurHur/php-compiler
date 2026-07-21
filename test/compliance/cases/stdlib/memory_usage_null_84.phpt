--TEST--
memory_get_usage/memory_get_peak_usage(null) DEP+coerce on 8.4 (#21615)
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
issue_21615_memory_usage_null.php
--EXPECT--
DEP
memory_get_usage OK
DEP
memory_get_peak_usage OK
