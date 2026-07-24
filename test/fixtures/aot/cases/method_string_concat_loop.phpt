--TEST--
AOT: method loop-carried string concat accumulates (#22845)
--FILE--
<?php
final class H
{
    public static function xs(): string
    {
        $out = '';
        for ($i = 0; $i < 3; ++$i) {
            $out .= 'X';
        }

        return $out;
    }
}
echo H::xs();
?>
--EXPECT--
XXX
