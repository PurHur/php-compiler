<?php

declare(strict_types=1);

/**
 * Maintainer repro: forward_static_call() inherited private static (#11919).
 *
 * Zend: TypeError — cannot access private method Child::secret()
 * VM (before fix): reaches callee and prints fail
 */

class FscPrivateStaticParent
{
    private static function secret(): int
    {
        return 7;
    }
}

class FscPrivateStaticChild extends FscPrivateStaticParent
{
    public static function probe(): void
    {
        try {
            forward_static_call([self::class, 'secret']);
            echo "fail: forward_static_call reached private static\n";
            exit(1);
        } catch (\TypeError $e) {
            if (!str_contains($e->getMessage(), 'cannot access private method')) {
                echo 'fail: unexpected TypeError: ', $e->getMessage(), "\n";
                exit(1);
            }
        }

        try {
            forward_static_call_array([self::class, 'secret'], []);
            echo "fail: forward_static_call_array reached private static\n";
            exit(1);
        } catch (\TypeError $e) {
            if (!str_contains($e->getMessage(), 'cannot access private method')) {
                echo 'fail: unexpected TypeError: ', $e->getMessage(), "\n";
                exit(1);
            }
        }

        echo "ok\n";
    }
}

FscPrivateStaticChild::probe();
