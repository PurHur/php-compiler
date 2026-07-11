<?php
$hash = password_hash('x', PASSWORD_BCRYPT);
$i = password_get_info($hash);
echo ($i['algoName'] ?? 'MISSING') . "\n";
echo var_export($i['algoName'] ?? 'MISSING', true) . "\n";
