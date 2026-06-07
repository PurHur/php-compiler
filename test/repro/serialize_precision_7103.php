<?php

declare(strict_types=1);

ini_set('serialize_precision', '2');
echo serialize(1.239), "\n";
echo serialize(['x' => 1.239]), "\n";
