--TEST--
stdlib: inline __DIR__/__FILE__ magic constants as file stat call arguments (#9136, #9127)
--RUNFILE--
magic_const_filestat_run.php
--EXPECT--
true
true
true
true
false
true
true
true
"dir"
true
