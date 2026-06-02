<?php
$g = getmygrgid();
$i = getmyinode();
echo $g >= 0 ? "1\n" : "0\n";
echo $i !== false && $i > 0 ? "1\n" : "0\n";
echo getmygrgid() === $g ? "1\n" : "0\n";
echo getmyinode() === $i ? "1\n" : "0\n";
