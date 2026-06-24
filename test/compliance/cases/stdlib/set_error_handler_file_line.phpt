--TEST--
set_error_handler() receives trigger file and line for builtin warnings (#11163, Zend/zend_execute.c)
--RUNFILE--
set_error_handler_file_line/script.php
--EXPECT--
severity=2
file_match=yes
line_gt0=yes
