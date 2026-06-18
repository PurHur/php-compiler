--TEST--
Language: backed enum declaration and case fetch (#1356)
--FILE--
<?php
enum Status: string {
    case Active = 'active';
    case Done = 'done';
}
echo Status::Active->value;
echo "\n";
echo Status::Done->value;
echo "\n";
echo enum_exists('Status') ? '1' : '0';
echo "\n";
echo enum_exists('Missing') ? '1' : '0';
--EXPECT--
active
done
1
0
