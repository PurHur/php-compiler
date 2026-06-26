--TEST--
stdlib serialize() __serialize() circular object — r: reference marker (#11903, ext/standard/var.c)
--FILE--
<?php
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
--EXPECT--
has_r_marker:yes
O:13:"CircSerialize":1:{s:4:"self";O:13:"CircSerialize":1:{s:4:"self";r:2;}}
