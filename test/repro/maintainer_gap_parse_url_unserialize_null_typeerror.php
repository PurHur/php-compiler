<?php

$fail = 0;

foreach ([
    'unserialize' => static fn () => unserialize(null),
] as $label => $factory) {
    try {
        $factory();
        echo "fail: $label did not throw\n";
        ++$fail;
    } catch (TypeError $e) {
        if (!str_contains($e->getMessage(), 'must be of type string, null given')) {
            echo "fail: $label wrong message: ", $e->getMessage(), "\n";
            ++$fail;
        }
    } catch (Throwable $e) {
        echo "fail: $label ", get_class($e), ': ', $e->getMessage(), "\n";
        ++$fail;
    }
}

echo 0 === $fail ? "ok\n" : "fail\n";
