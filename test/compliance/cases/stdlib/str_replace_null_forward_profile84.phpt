--TEST--
stdlib str_replace()/str_ireplace()/preg_replace() null subject soft-null on 8.4 (#21198, re-#18914/#19241)
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
str_replace_null_forward_profile84.php
--EXPECT--
DEP
str_replace OK ''
DEP
str_ireplace OK ''
DEP
preg_replace OK 'x'
