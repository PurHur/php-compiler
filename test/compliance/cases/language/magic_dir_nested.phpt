--TEST--
Language: __DIR__ in included helper matches helper directory (#707, #85)
--RUNFILE--
magic_dir_nested/entry.php
--EXPECTREGEX--
#/sub\s*$
--
