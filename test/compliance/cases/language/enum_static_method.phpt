--TEST--
Language: backed enum static method (#2299)
--FILE--
<?php
enum Status: string {
    case Active = 'active';

    public static function tag(): string {
        return 'enum-static-ok';
    }
}
echo Status::tag();
echo "\n";
echo Status::Active->value;
--EXPECT--
enum-static-ok
active
