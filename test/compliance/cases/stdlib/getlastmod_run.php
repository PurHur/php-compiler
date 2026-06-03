<?php
$m = getlastmod();
echo is_int($m) && $m > 0 ? "mtime\n" : "bad\n";
echo getlastmod() === $m ? "stable\n" : "bad\n";
