<?php

declare(strict_types=1);

set_exception_handler(function (Throwable $e) {
    echo "handled\n";
});
throw new Exception('probe');
