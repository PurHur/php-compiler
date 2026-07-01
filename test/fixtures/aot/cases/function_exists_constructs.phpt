--TEST--
AOT: function_exists() false for language constructs (#14738)
--FILE--
<?php
echo function_exists('__halt_compiler') ? 'halt_yes' : 'halt_no', "\n";
echo function_exists('eval') ? 'eval_yes' : 'eval_no', "\n";
echo function_exists('exit') ? 'exit_yes' : 'exit_no', "\n";
echo function_exists('die') ? 'die_yes' : 'die_no', "\n";
echo function_exists('strlen') ? 'strlen_yes' : 'strlen_no', "\n";
--EXPECT--
halt_no
eval_no
exit_no
die_no
strlen_yes
