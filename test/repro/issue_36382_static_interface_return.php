<?php
// #36382 — AOT: return typed interface static property exits 0 before caller continues.
interface RFI36382
{
    public function x(): string;
}
class RF36382 implements RFI36382
{
    public function x(): string
    {
        return 'hi';
    }
}
class AF36382
{
    protected static ?RFI36382 $rf = null;

    public static function set(RFI36382 $x): void
    {
        static::$rf = $x;
    }

    public static function determine(): RFI36382
    {
        if (static::$rf) {
            return static::$rf;
        }
        throw new RuntimeException('missing');
    }
}
echo "START\n";
AF36382::set(new RF36382());
echo "SET\n";
$r = AF36382::determine();
echo "GOT\n";
echo $r->x(), "\n";
echo "OK\n";
