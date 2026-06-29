<?php
declare(strict_types=1);

class C {}

function id(object $o): string
{
    return get_class($o);
}

$cloneClass = id(clone new C());
$castClass = id((object) ['a' => 1]);

if ('C' !== $cloneClass) {
    fwrite(STDERR, "clone new: expected C, got {$cloneClass}\n");
    exit(1);
}
if ('stdClass' !== $castClass) {
    fwrite(STDERR, "object cast: expected stdClass, got {$castClass}\n");
    exit(1);
}

echo "OK\n";
