<?php
declare(strict_types=1);

echo 'query0: ', var_export(setlocale(LC_ALL, '0'), true), "\n";
setlocale(LC_ALL, 'C');
echo 'query null after C: ', var_export(setlocale(LC_ALL, null), true), "\n";
