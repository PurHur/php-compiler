--TEST--
Language: __DIR__ in included helper (JIT, #707, #85)
--RUNFILE--
magic_dir_nested/entry.php
--EXPECTREGEX--
#/sub\s*$
--
