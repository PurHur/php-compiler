<?php

declare(strict_types=1);

$p = sys_get_temp_dir().'/phpc_touch_shutdown_'.uniqid('', true).'.tmp';
touch($p, 1);
register_shutdown_function(static function (): void {});
echo "ok\n";
@unlink($p);
