<?php

$t = new class {
    public function __toString(): string {
        throw new Exception('boom');
    }
};

try {
    echo strlen($t);
} catch (Throwable $e) {
    echo 'caught: ', $e->getMessage(), "\n";
}
