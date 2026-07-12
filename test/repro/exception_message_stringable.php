<?php
declare(strict_types=1);

class S implements Stringable {
    public function __toString(): string
    {
        return 'boom';
    }
}

class ThrowingS implements Stringable {
    public function __toString(): string
    {
        throw new Exception('inner');
    }
}

foreach ([Exception::class, Error::class, RuntimeException::class] as $class) {
    try {
        new $class(new S());
        echo $class, " constructed\n";
    } catch (TypeError $e) {
        echo $class, ' TypeError: ', $e->getMessage(), "\n";
    }
}

try {
    new Exception(new ThrowingS());
} catch (Throwable $e) {
    echo 'ThrowingS: ', get_class($e), ': ', $e->getMessage(), "\n";
}
