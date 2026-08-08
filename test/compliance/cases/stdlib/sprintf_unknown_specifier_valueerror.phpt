--TEST--
stdlib sprintf/printf/vsprintf unknown specifier → ValueError (#27826, formatted_print.c)
--FILE--
<?php
foreach (['sprintf', 'printf', 'vsprintf'] as $f) {
    try {
        if ($f === 'vsprintf') {
            $f('%Z', [1]);
        } elseif ($f === 'printf') {
            ob_start();
            printf('%Z', 1);
            ob_end_clean();
        } else {
            sprintf('%Z', 1);
        }
        echo "$f OK\n";
    } catch (Throwable $e) {
        echo $f, ' ', get_class($e), ':', $e->getMessage(), "\n";
    }
}
try {
    sprintf('%Y', 1);
    echo "Y OK\n";
} catch (Throwable $e) {
    echo 'Y ', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    sprintf('%\\', 1);
    echo "backslash OK\n";
} catch (Throwable $e) {
    echo 'backslash ', get_class($e), ':', $e->getMessage(), "\n";
}
?>
--EXPECT--
sprintf ValueError:Unknown format specifier "Z"
printf ValueError:Unknown format specifier "Z"
vsprintf ValueError:Unknown format specifier "Z"
Y ValueError:Unknown format specifier "Y"
backslash ValueError:Unknown format specifier "\"
