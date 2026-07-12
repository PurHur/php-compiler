<?php

declare(strict_types=1);

$p = sys_get_temp_dir().'/phpc_touch_err_'.uniqid('', true).'.tmp';
touch($p, 1);
set_error_handler(static fn (): bool => true);
echo "ok\n";
@unlink($p);
