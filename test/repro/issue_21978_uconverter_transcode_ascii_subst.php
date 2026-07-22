<?php

declare(strict_types=1);

foreach (['café', "\xE2\x82\xAC", 'abc'] as $s) {
    $r = UConverter::transcode($s, 'ASCII', 'UTF-8');
    echo bin2hex($s), ' => ', (false === $r ? 'false' : bin2hex($r)), "\n";
}
$u = new UConverter('ASCII', 'UTF-8');
$c = $u->convert('café');
echo 'convert=', (false === $c ? 'false' : bin2hex($c)), "\n";
