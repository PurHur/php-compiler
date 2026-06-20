<?php
declare(strict_types=1);
ob_start();
phpinfo(INFO_GENERAL);
$vm = ob_get_clean();
echo strlen($vm), "\n";
echo str_contains($vm, 'PHP Version') ? "has_version\n" : "missing_version\n";
