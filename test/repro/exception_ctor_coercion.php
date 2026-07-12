<?php
declare(strict_types=1);

class Plain {
    public function __toString(): string
    {
        return 'plain';
    }
}

enum E: string { case A = 'a'; }

foreach (
    [
        'int' => 123,
        'float' => 1.5,
        'bool' => true,
        'plain object' => new Plain(),
    ] as $label => $message
) {
    try {
        new Exception($message);
        echo $label, " constructed\n";
    } catch (TypeError $e) {
        echo $label, ' TypeError: ', $e->getMessage(), "\n";
    }
}

try {
    new Exception(E::A);
} catch (TypeError $e) {
    echo 'enum:', $e->getMessage(), "\n";
}

try {
    new Exception([]);
} catch (TypeError $e) {
    echo 'array:', $e->getMessage(), "\n";
}
