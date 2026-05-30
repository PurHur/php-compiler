--TEST--
language eval() parse error sets error_get_last (VM, issue #3358)
--RUNFILE--
eval_parse_error_run/entry.php
--EXPECT--
has-error
4
eval-file
