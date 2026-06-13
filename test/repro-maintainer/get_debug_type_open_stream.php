<?php
$h = fopen('php://memory', 'r+');
echo 'get_debug_type=', get_debug_type($h), "\n";
echo 'gettype=', gettype($h), "\n";
