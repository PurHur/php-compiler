--TEST--
Language: typed set(string $value) and short set { } on typed hooked property (#29419)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class Typed {
    public string $x = 'a' {
        set(string $value) { $this->x = $value . '!'; }
    }
}
class Short {
    public string $y = 'a' {
        set { $this->y = $value . '!'; }
    }
}
$t = new Typed;
$t->x = 'b';
echo $t->x, "\n";
$s = new Short;
$s->y = 'c';
echo $s->y, "\n";
--EXPECT--
b!
c!
