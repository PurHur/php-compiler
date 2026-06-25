<?php

declare(strict_types=1);

$u = posix_uname();
echo 'domainname_present=', (int) isset($u['domainname']), "\n";
echo 'domainname=', var_export($u['domainname'] ?? null, true), "\n";
