<?php

declare(strict_types=1);

foreach (['on', 'off'] as $v) {
    $r = parse_ini_string("flag = $v");
    echo 'parse_ini_'.$v.':'.var_export($r['flag'] ?? null, true)."\n";
}
echo "done\n";
