<?php

class ParentConst
{
    public const X = 1;
}

class ChildConst extends ParentConst
{
    public const Y = self::X;
}

class GrandChildConst extends ChildConst
{
    public const Z = self::X;
}

echo ChildConst::Y === 1 && GrandChildConst::Z === 1 ? "ok\n" : "fail\n";
