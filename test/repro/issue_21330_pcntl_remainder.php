<?php
declare(strict_types=1);
/** Issue #21330 repro — pcntl_errno / wifcontinued / sigwaitinfo surface. */
foreach (['pcntl_errno', 'pcntl_wifcontinued', 'pcntl_sigwaitinfo'] as $f) {
    echo $f, '=', function_exists($f) ? 'yes' : 'MISSING', "\n";
}
