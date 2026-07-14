<?php

declare(strict_types=1);

$ok = define(null, 42);
echo 'define=', ($ok ? 'true' : 'false'), ' constant=', constant(''), "\n";
