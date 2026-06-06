<?php
declare(strict_types=1);
enum Sep: string { case Pipe = '|'; }
try {
    implode(Sep::Pipe, ['a', 'b']);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
