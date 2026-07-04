--TEST--
stdlib printf()/sprintf() null format JIT — TypeError not LogicException (#16042, ext/standard/sprintf.c)
--FILE--
<?php
$printfOk = false;
try {
    printf(null);
} catch (TypeError $e) {
    $printfOk = true;
}
echo $printfOk ? "printf TypeError\n" : "printf no error\n";

$sprintfOk = false;
try {
    sprintf(null);
} catch (TypeError $e) {
    $sprintfOk = true;
}
echo $sprintfOk ? "sprintf TypeError\n" : "sprintf no error\n";
?>
--EXPECT--
printf TypeError
sprintf TypeError
