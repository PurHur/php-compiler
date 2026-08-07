<?php

declare(strict_types=1);

/**
 * Global RedisClusterException when phpredis is not loaded on the host (PECL phpredis; #28094).
 *
 * php-src/phpredis: {@code class RedisClusterException extends RuntimeException}.
 */
if (!\class_exists(\RedisClusterException::class, false)) {
    class RedisClusterException extends \RuntimeException
    {
    }
}
