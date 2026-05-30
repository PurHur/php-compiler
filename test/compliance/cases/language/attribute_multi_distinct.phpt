--TEST--
Language: multiple distinct attributes on class (#3718)
--FILE--
<?php
#[\AllowDynamicProperties]
class C {
    public int $x = 1;
}
echo $c = (new C)->x, "\n";
--EXPECT--
1
