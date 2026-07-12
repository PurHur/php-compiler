<?php
$info = password_get_info('$2y$10$abcdefghijklmnopqrstuv');
echo $info['algoName'] . "\n";
echo var_export($info['algoName'] ?? 'default', true) . "\n";
