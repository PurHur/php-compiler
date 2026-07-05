<?php

declare(strict_types=1);

$fp = fopen('php://memory', 'r+');
fwrite($fp, 'hello');
echo stream_get_contents($fp, offset: 1);
