--TEST--
Runtime __DIR__ and __FILE__ reflect the included script (issue #707)
--RUNFILE--
magic_dir_nested/entry.php
--EXPECT--
magic_dir_nested
helper.php
