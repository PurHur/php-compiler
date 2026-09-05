<?php
abstract class Base36382b
{
    protected static string $creatorClass;

    public static function getClass(): string
    {
        return static::$creatorClass;
    }
}

final class Child36382b extends Base36382b
{
    protected static string $creatorClass = 'Target36382b';
}

class Target36382b {}

echo "S1\n";
$c = Child36382b::getClass();
echo "S2 c=[$c] len=".strlen($c)."\n";
echo "S3\n";
echo "S4\n";
$ce = class_exists($c, false);
echo "S5 ce=".($ce ? '1' : '0')."\n";
$ce2 = class_exists('Target36382b', false);
echo "S6 lit=".($ce2 ? '1' : '0')."\n";
$ce3 = class_exists($c, true);
echo "S7 ce_autoload=".($ce3 ? '1' : '0')."\n";
