--TEST--
Language: backed enum implements interface — instance methods + instanceof (#3373)
--FILE--
<?php
interface HasName {
    public function label(): string;
}

enum Status: string implements HasName {
    case Open = 'open';
    case Closed = 'closed';

    public function label(): string {
        return $this->name;
    }
}

echo Status::Open->label();
echo "\n";
echo Status::Open instanceof HasName ? '1' : '0';
echo "\n";
echo Status::Open;
--EXPECT--
Open
1
open
