<?php
declare(strict_types=1);
$f = 'exit';
$d = 'die';
echo 'exit_callable=', var_export(is_callable($f), true), "\n";
echo 'die_callable=', var_export(is_callable($d), true), "\n";
