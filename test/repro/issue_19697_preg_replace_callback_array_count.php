<?php
$count = 0;
$r = preg_replace_callback_array(["/a/" => fn($m) => "X"], "aa", -1, $count);
echo "r=$r count=$count\n";
$count = 0;
$r = preg_replace_callback_array(["/a/" => fn($m) => "X"], "aa", count: $count);
echo "named r=$r count=$count\n";
