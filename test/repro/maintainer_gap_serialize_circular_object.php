<?php
declare(strict_types=1);
// Issue #11903 — serialize() __serialize() circular object must emit r: marker (ext/standard/var.c).

class CircSerialize
{
    public function __serialize(): array
    {
        return ['self' => $this];
    }
}

$s = serialize(new CircSerialize());
echo 'has_r_marker:', str_contains($s, 'r:') ? 'yes' : 'no', "\n";
echo $s, "\n";
