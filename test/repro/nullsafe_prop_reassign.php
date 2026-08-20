<?php
// #32749 — prior ?-> on null must not poison later ?-> after reassignment (AOT stdClass remap).
class C {
    public int $x = 5;
}
$c = null;
echo $c?->x ?? 'n';
$c = new C();
echo $c?->x;
