<?php
echo function_exists('getlastmod') ? "1\n" : "0\n";
$m = getlastmod();
echo is_int($m) && $m > 0 ? "1\n" : "0\n";
echo getlastmod() === $m ? "1\n" : "0\n";
