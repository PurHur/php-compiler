--TEST--
Language: backed enum implements interface metadata (#2299)
--FILE--
<?php
interface Labeled
{
}

enum Status: string implements Labeled
{
    case Active = 'active';
}

echo Status::Active;
echo "\n";
echo enum_exists('Status') ? '1' : '0';
--EXPECT--
active
1
