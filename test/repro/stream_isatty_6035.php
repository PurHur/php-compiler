<?php
echo function_exists('stream_isatty') ? '1' : '0', "\n";
$memory = fopen('php://memory', 'r+');
echo stream_isatty($memory) ? '1' : '0', "\n";
fclose($memory);
