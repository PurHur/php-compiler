<?php

declare(strict_types=1);

if (false !== file_exists(null)) {
    echo "fail\n";
    exit(1);
}
echo "ok\n";
