--TEST--
AOT serialize() nullable null-default property (#14619, ext/standard/var.c)
--FILE--
<?php
class Box {
    public ?string $s = null;
    public string $t = 'x';
}
echo serialize(new Box);

--EXPECT--
O:3:"Box":2:{s:1:"s";N;s:1:"t";s:1:"x";}
