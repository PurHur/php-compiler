<?php
declare(strict_types=1);

class ParentLsb
{
    public static function who(): string
    {
        return static::class;
    }
}

class ChildLsb extends ParentLsb
{
    public static function who(): string
    {
        return parent::who();
    }

    public static function selfWho(): string
    {
        return static::who();
    }
}

class GrandChildLsb extends ChildLsb
{
}

echo 'C=' . ChildLsb::who() . "\n";
echo 'D=' . GrandChildLsb::who() . "\n";
echo 'instance=' . ChildLsb::who() . "\n";
echo 'direct=' . ParentLsb::who() . "\n";
echo 'self=' . ChildLsb::selfWho() . "\n";
