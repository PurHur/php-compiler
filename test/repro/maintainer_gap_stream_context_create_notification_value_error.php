<?php

declare(strict_types=1);

try {
    stream_context_create(['notification' => static function (): void {}]);
    echo "no-error\n";
} catch (ValueError $e) {
    echo "ok\n";
    echo $e->getMessage(), "\n";
}
