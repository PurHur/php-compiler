<?php

declare(strict_types=1);

foreach (['gc_collect_cycles', 'gc_disable'] as $fn) {
    try {
        $fn(1);
        echo "$fn: NO_ERROR\n";
    } catch (ArgumentCountError $e) {
        echo "$fn: ArgumentCountError\n";
    } catch (Throwable $e) {
        echo "$fn: ", get_class($e), ': ', $e->getMessage(), "\n";
    }
}

echo "ok\n";
