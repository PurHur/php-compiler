--TEST--
runtime CLI $argc / $argv globals (sapi/cli parity, #4139)
--RUNFILE--
cli_argv.php
--EXPECTF--
argc=1 argv=1
count=1
first=%s
