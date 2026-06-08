--TEST--
Language: constructor property promotion on non-readonly class (issue #4770)
--FILE--
<?php
class Box {
    public function __construct(private string $name) {
        echo $name, "\n";
    }
}
new Box('ok');
--EXPECT--
ok
