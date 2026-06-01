<?php
echo function_exists('getmygrgid') ? '1' : '0', "\n";
echo function_exists('getmyinode') ? '1' : '0', "\n";
$g = getmygrgid();
$i = getmyinode();
echo $g >= 0 ? '1' : '0', "\n";
echo $i !== false && $i > 0 ? '1' : '0', "\n";
