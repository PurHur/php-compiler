<?php
echo function_exists('posix_getpgrp') ? 'yes' : 'no', "\n";
$a = posix_getpgrp();
$b = posix_getpgid(0);
echo ($a === $b && $a > 0) ? 'match' : 'mismatch', "\n";
echo $a, "\n";
