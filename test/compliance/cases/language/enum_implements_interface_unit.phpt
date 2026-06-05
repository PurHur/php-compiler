--TEST--
Language: unit enum implements interface — instance methods + instanceof (#5703)
--FILE--
<?php
interface Labeled {
    public function tag(): string;
}

enum Status implements Labeled {
    case Open;

    public function tag(): string {
        return 'open';
    }
}

echo Status::Open->tag();
echo "\n";
echo Status::Open instanceof Labeled ? '1' : '0';
echo "\n";
--EXPECT--
open
1
