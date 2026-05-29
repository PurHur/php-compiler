--TEST--
PHP 8.4 asymmetric property visibility: public private(set) (#3165)
--FILE--
<?php
class Demo {
    public private(set) string $name = 'x';

    public function mutate(): void {
        $this->name = 'y';
    }
}

$d = new Demo();
echo $d->name, "\n";
$d->mutate();
echo $d->name, "\n";
$d->name = 'z';
--EXPECT--
x
y
--EXPECT_EXIT--
255
