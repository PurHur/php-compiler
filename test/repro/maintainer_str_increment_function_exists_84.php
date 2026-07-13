<?php

declare(strict_types=1);

echo 'function_exists=', function_exists('str_increment') ? 'true' : 'false', "\n";
echo 'is_callable=', is_callable('str_increment') ? 'true' : 'false', "\n";
echo 'str_increment=', str_increment('z'), "\n";
echo 'str_decrement=', str_decrement('b'), "\n";
