--TEST--
Language: AOT null literal inside catch — boxed null, not nullptr (#34659)
--FILE--
<?php
try {
    throw new Exception('x');
} catch (Throwable $e) {
    $x = null;
    echo ($x === null ? 'Y' : 'N'), "\n";
}
--EXPECT--
Y
