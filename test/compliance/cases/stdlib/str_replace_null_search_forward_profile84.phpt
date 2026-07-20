--TEST--
stdlib str_replace()/str_ireplace() null $search soft-null on 8.4 (#21189, reverts #20173)
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
str_replace_null_search_forward_profile84.php
--EXPECT--
str_replace: OK 'hay'
str_ireplace: OK 'Hay'
