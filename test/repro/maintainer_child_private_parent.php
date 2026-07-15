<?php

class ParentOnly {
    private string $secret = 'hidden';

    public function parentRead(): string {
        return $this->secret;
    }
}

class Child extends ParentOnly {
    public function read(): string {
        return $this->secret;
    }
}

try {
    echo (new Child())->read(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    echo (new Child())->parentRead(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
