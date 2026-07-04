<?php

declare(strict_types=1);

try {
    is_subclass_of('stdClass', 'Traversable', autoload: false);
    echo "fail: is_subclass_of did not throw\n";
    exit(1);
} catch (\Error $e) {
    if ('Unknown named parameter $autoload' !== $e->getMessage()) {
        echo 'fail: is_subclass_of: '.$e->getMessage()."\n";
        exit(1);
    }
}

try {
    is_a(new stdClass(), 'stdClass', autoload: false);
    echo "fail: is_a did not throw\n";
    exit(1);
} catch (\Error $e) {
    if ('Unknown named parameter $autoload' !== $e->getMessage()) {
        echo 'fail: is_a: '.$e->getMessage()."\n";
        exit(1);
    }
}

if (!class_exists('Nonexistent', autoload: false)) {
    echo "class_exists ok\n";
} else {
    echo "fail: class_exists returned true\n";
    exit(1);
}

if (interface_exists('Traversable', autoload: false)) {
    echo "interface_exists ok\n";
} else {
    echo "fail: interface_exists returned false\n";
    exit(1);
}

echo "ok\n";
