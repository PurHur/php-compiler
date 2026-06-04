--TEST--
Language: echo/print/heredoc on backed enum case throw Error (#5843, zend_operators.c)
--FILE--
<?php
enum I: int { case A = 1; }
enum E: string { case X = 'x'; }
$e = E::X;

try {
    echo I::A;
    echo "echo fail\n";
} catch (Error $e1) {
    echo 'echo:', $e1->getMessage(), "\n";
}

try {
    print I::A;
    echo "print fail\n";
} catch (Error $e2) {
    echo 'print:', $e2->getMessage(), "\n";
}

try {
    echo <<<HD
e=$e
HD;
    echo "heredoc fail\n";
} catch (Error $e3) {
    echo 'heredoc:', $e3->getMessage(), "\n";
}
--EXPECT--
echo:Object of class I could not be converted to string
print:Object of class I could not be converted to string
heredoc:Object of class E could not be converted to string
