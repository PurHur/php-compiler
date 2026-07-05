<?php

declare(strict_types=1);

@diskfreespace('/no/such/phpc-diskfreespace-warning');
$err = error_get_last();
echo ($err !== null ? $err['message'] : 'no_error')."\n";

@disk_free_space('/no/such/phpc-diskfreespace-warning');
$err = error_get_last();
echo ($err !== null ? $err['message'] : 'no_error')."\n";
