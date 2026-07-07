<?php

declare(strict_types=1);

foreach (['array_find', 'array_find_key'] as $fn) {
    try {
        $fn === 'array_find' ? array_find([1], null) : array_find_key(['a' => 1], null);
        fwrite(STDERR, $fn.": uncaught\n");
        exit(1);
    } catch (TypeError $e) {
        echo $fn, ': ', $e->getMessage(), "\n";
    } catch (LogicException $e) {
        fwrite(STDERR, $fn.': LogicException: '.$e->getMessage()."\n");
        exit(1);
    }
}

echo "ok\n";
