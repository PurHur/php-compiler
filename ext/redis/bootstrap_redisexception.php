<?php

declare(strict_types=1);

/**
 * Global RedisException when phpredis is not loaded on the host (PECL phpredis; #6098).
 */
if (!\class_exists(\RedisException::class, false)) {
    class RedisException extends \Exception
    {
    }
}
