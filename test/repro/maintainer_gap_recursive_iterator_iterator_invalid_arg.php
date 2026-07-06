<?php

declare(strict_types=1);

try {
    new RecursiveIteratorIterator(new ArrayIterator([1]));
    fwrite(STDERR, "no exception\n");
    exit(1);
} catch (InvalidArgumentException $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
    exit(0);
} catch (LogicException $e) {
    fwrite(STDERR, 'LogicException: '.$e->getMessage()."\n");
    exit(1);
}
