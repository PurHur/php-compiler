--TEST--
AOT: compile-time literal include executes included file (issue #54, #485)
--RUNFILE--
include_literal_path/entry.php
--EXPECT--
ok
