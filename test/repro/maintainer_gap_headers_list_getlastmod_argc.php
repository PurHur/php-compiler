<?php

declare(strict_types=1);

foreach (['headers_list', 'getlastmod'] as $fn) {
    try {
        $fn(null);
        echo "{$fn}: NO_THROW\n";
    } catch (ArgumentCountError $e) {
        echo $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}
