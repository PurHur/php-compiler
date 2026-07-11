<?php

declare(strict_types=1);

try {
    func_get_args();
    echo "no_error\n";
} catch (Error $e) {
    echo 'Error: ', $e->getMessage(), "\n";
} catch (LogicException $e) {
    echo 'LogicException: ', $e->getMessage(), "\n";
}
