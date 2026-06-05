--TEST--
stdlib vprintf()/vfprintf()/printf() — enum case operands match sprintf engine (#6152, #5580)
--FILE--
<?php
enum E: int {
    case A = 1;
}

$out = fopen('php://stdout', 'w');
@vfprintf($out, '%d', [E::A]);
echo "\n";
fclose($out);
$err = error_get_last();
echo 'vfprintf warning: ', $err['message'] ?? '', "\n";

try {
    printf('%s', E::A);
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}

try {
    echo vsprintf('%s', [E::A]);
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}

echo 'sprintf %d: ', @sprintf('%d', E::A), "\n";
--EXPECT--
1
vfprintf warning: Object of class E could not be converted to int
Error: Object of class E could not be converted to string
Error: Object of class E could not be converted to string
sprintf %d: 1
