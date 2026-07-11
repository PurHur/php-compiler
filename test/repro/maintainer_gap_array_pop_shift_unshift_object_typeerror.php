<?php

declare(strict_types=1);

$expectedNotice = 'Only variables should be passed by reference';

foreach (['array_pop', 'array_shift', 'array_unshift'] as $fn) {
    try {
        if ('array_unshift' === $fn) {
            $fn(new stdClass(), 1);
        } else {
            $fn(new stdClass());
        }
        echo $fn, ": fail: no TypeError\n";
        exit(1);
    } catch (TypeError $e) {
        if (!str_contains($e->getMessage(), 'must be of type array, stdClass given')) {
            echo $fn, ': fail: ', $e->getMessage(), "\n";
            exit(1);
        }
    } catch (Throwable $e) {
        echo $fn, ': fail: ', get_class($e), ': ', $e->getMessage(), "\n";
        exit(1);
    }
    $last = error_get_last();
    if (null === $last || !str_contains($last['message'], $expectedNotice)) {
        echo $fn, ": fail: missing E_NOTICE\n";
        exit(1);
    }
}

$o = new stdClass();
foreach (['array_pop', 'array_shift', 'array_unshift'] as $fn) {
    try {
        if ('array_unshift' === $fn) {
            $fn($o, 1);
        } else {
            $fn($o);
        }
        echo $fn, "_var: fail: no TypeError\n";
        exit(1);
    } catch (TypeError $e) {
        if (!str_contains($e->getMessage(), 'must be of type array, stdClass given')) {
            echo $fn, '_var: fail: ', $e->getMessage(), "\n";
            exit(1);
        }
    } catch (Throwable $e) {
        echo $fn, '_var: fail: ', get_class($e), ': ', $e->getMessage(), "\n";
        exit(1);
    }
}

echo "ok\n";
