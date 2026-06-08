--TEST--
language eval() parse error sets eval()'d code file (VM, issue #4410)
--RUNFILE--
eval_parse_error_run/entry.php
--EXPECT--
ParseError
eval-file
