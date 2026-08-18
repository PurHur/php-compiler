--TEST--
Language: warnings inside functions/methods/closures cite inner opline (#32040, Zend/zend_execute.c)
--FILE--
<?php
function warn_line(int $errno, string $message, string $file, int $line): bool
{
    if (E_WARNING === $errno || E_DEPRECATED === $errno) {
        echo (E_WARNING === $errno ? 'W:' : 'D:'), $message, '@', $line, "\n";
    }

    return true;
}
set_error_handler('warn_line');

function inner(): void
{
    $x = $missing;
}
inner();

class C
{
    public function go(): void
    {
        $x = $missing_m;
    }
}
(new C)->go();

$fn = function () {
    $x = 5.5 % 2;
};
$fn();

$y = 5.5 % 2;
echo "done\n";
--EXPECT--
W:Undefined variable $missing@14
W:Undefined variable $missing_m@22
D:Implicit conversion from float 5.5 to int loses precision@28
D:Implicit conversion from float 5.5 to int loses precision@32
done
