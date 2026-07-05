<?php
class Demo {
    public (private(set)) string $name = 'a';
}

$d = new Demo();
echo "read: {$d->name}\n";
try {
    $d->name = 'b';
    echo "no throw\n";
} catch (Error $e) {
    echo 'caught ', get_class($e), ': ', $e->getMessage(), "\n";
}

