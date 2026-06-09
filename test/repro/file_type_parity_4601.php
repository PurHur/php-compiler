<?php
$tmp = tempnam(sys_get_temp_dir(), 'f');
file_put_contents($tmp, "a\nb\n");
try {
    var_dump(count(file($tmp, "2")));
} catch (Throwable $e) {
    echo 'flags: ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    file(123);
    echo "int_path: no_exception\n";
} catch (Throwable $e) {
    echo 'int_path: ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    file([]);
} catch (Throwable $e) {
    echo 'array_path: ', get_class($e), ': ', $e->getMessage(), "\n";
}
unlink($tmp);
