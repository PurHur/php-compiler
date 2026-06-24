<?php

echo 'memory_limit=', ini_get('memory_limit'), "\n";
echo 'max_execution_time=', json_encode(ini_get('max_execution_time')), "\n";
echo 'default_charset=', json_encode(ini_get('default_charset')), "\n";
