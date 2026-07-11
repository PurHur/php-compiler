<?php

declare(strict_types=1);

@fprintf(STDOUT, '%s', []);
$err = error_get_last();
echo 'output_ok ', ($err['message'] ?? ''), "\n";
