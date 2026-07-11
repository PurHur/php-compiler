<?php
// AOT compile-only: serialize() nullable null-default property (#14619, ext/standard/var.c).
class Box {
    public ?string $s = null;
    public string $t = 'x';
}
serialize(new Box);
unserialize(serialize(new Box));
