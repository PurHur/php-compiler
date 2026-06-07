<?php

declare(strict_types=1);

if (!enum_exists('ExitStatus', false)) {
    fwrite(STDERR, "ExitStatus enum not registered\n");
    exit(1);
}

exit(ExitStatus::Success);
