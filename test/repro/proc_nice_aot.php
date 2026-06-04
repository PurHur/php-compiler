<?php
$ok = proc_nice(0);
echo is_bool($ok) ? "bool\n" : "bad\n";
echo "ok\n";
