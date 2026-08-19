<?php
$s = "hello world";
echo isset($s[0]) ? "y" : "n";
echo isset($s[4]) ? "y" : "n";
echo isset($s[5]) ? "y" : "n";
echo isset($s[10]) ? "y" : "n";
echo isset($s[11]) ? "y" : "n";
echo "\n";
echo str_replace("o", "0", $s), "\n";
