<?php

declare(strict_types=1);

echo 'function_exists=', var_export(function_exists('gc_collect_cycles'), true), "\n";
echo 'is_callable=', var_export(is_callable('gc_collect_cycles'), true), "\n";
