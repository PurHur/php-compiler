<?php
declare(strict_types=1);

echo PASSWORD_BCRYPT_DEFAULT_COST, PHP_EOL;
$h = password_hash('x', PASSWORD_DEFAULT);
$info = password_get_info($h);
echo $info['options']['cost'], PHP_EOL;
echo substr($h, 0, 7), PHP_EOL;
