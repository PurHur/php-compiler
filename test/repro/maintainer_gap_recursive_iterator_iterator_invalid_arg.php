<?php

declare(strict_types=1);

try {
    new RecursiveIteratorIterator(new ArrayIterator([1]));
    echo "no exception\n";
} catch (InvalidArgumentException $e) {
    echo 'ok:', $e->getMessage(), "\n";
} catch (LogicException $e) {
    echo 'fail:LogicException:', $e->getMessage(), "\n";
}
