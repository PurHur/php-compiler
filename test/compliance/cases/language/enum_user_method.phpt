--TEST--
Language: unit enum instance method and $this->name (#3390)
--FILE--
<?php
enum Status {
    case Ok;
    case Err;
    public function label(): string {
        return $this->name;
    }
}
echo Status::Ok->label();
echo "\n";
echo Status::Err->label();
--EXPECT--
Ok
Err
