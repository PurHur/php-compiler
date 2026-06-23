<?php

declare(strict_types=1);

$o = new stdClass();
$o->x = 1;

echo property_exists($o, 'x') ? 'true' : 'false', "\n";
echo property_exists($o, 'y') ? 'true' : 'false', "\n";

$ok = property_exists($o, 'x') === true && property_exists($o, 'y') === false;
exit($ok ? 0 : 1);
