<?php

declare(strict_types=1);

echo PASSWORD_DEFAULT, "\n";
echo PASSWORD_DEFAULT === '2y' ? "cmp_ok\n" : "cmp_fail\n";
$hash = password_hash('secret', PASSWORD_DEFAULT);
echo password_verify('secret', $hash) ? "verify_ok\n" : "verify_fail\n";
