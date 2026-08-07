<?php

declare(strict_types=1);

/**
 * #28200 / re-#7294 — ExitStatus is a phantom; assert absence then exit(0).
 */
if (enum_exists('ExitStatus', false)) {
    fwrite(STDERR, "ExitStatus enum must not be registered\n");
    exit(1);
}

exit(0);
