<?php

declare(strict_types=1);

$key = 'PHPC_PUTENV_UNSET_'.(string) getmypid();
putenv($key.'=1');
putenv($key);
var_export(getenv($key));
