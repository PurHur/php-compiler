<?php
echo serialize(new stdClass);
echo "\n";
class O
{
    public $x = 1;
}
echo serialize(new O);
echo "\n";
class M
{
    public $x = 1;
    public $y = 'hi';
}
echo serialize(new M);
echo "\n";
class N
{
    public $a = [1, 2];
}
echo serialize(new N);
echo "\n";
