<?php
echo function_exists('readline') ? "exists\n" : "missing\n";
$r = readline();
echo ($r === false || is_string($r)) ? "ok\n" : "bad\n";
