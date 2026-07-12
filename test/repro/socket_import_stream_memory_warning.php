<?php

declare(strict_types=1);

$stream = fopen('php://memory', 'r+');
$result = @socket_import_stream($stream);
echo $result === false ? 'false' : 'not_false', "\n";
