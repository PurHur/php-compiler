<?php

declare(strict_types=1);

$v = @get_cfg_var('display_errors');
echo 'type=', gettype($v), "\n";
echo 'val=', var_export($v, true), "\n";

if (!is_string($v) || '' !== $v) {
    exit(1);
}
