--TEST--
stdlib readline_add_history/readline_list_history roundtrip (#7059)
--FILE--
<?php
readline_clear_history();
readline_add_history('line one');
readline_add_history('line two');
$hist = readline_list_history();
echo count($hist), "\n";
echo $hist[0], "\n";
echo $hist[1], "\n";
echo function_exists('readline_info') ? "info\n" : "noinfo\n";
echo function_exists('readline_clear_history') ? "clear\n" : "noclear\n";
?>
--EXPECT--
2
line one
line two
info
clear
