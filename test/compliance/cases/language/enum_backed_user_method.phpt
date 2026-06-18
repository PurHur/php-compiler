--TEST--
Language: backed enum instance method (#3390)
--FILE--
<?php
enum Status: string {
    case Active = 'active';
    public function label(): string {
        return $this->name;
    }
}
echo Status::Active->label();
echo "\n";
echo Status::Active->value;
--EXPECT--
Active
active
