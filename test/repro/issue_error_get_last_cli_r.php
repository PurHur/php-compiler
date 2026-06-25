<?php

declare(strict_types=1);

trigger_error('probe', E_USER_WARNING);
$last = error_get_last();
var_export($last);
