<?php
class Demo {
    private(set) string $name = 'a';
}

$d = new Demo();
echo "read: {$d->name}\n";
$d->name = 'b';
