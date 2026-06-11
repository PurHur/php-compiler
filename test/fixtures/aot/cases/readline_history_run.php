<?php
echo function_exists('readline_add_history') ? "exists\n" : "missing\n";
readline_clear_history();
readline_add_history('line one');
readline_add_history('line two');
$hist = readline_list_history();
echo count($hist), "\n";
echo $hist[0], "\n";
echo ($hist[1] === 'line two') ? "ok\n" : "bad\n";
