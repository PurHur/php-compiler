<?php
declare(strict_types=1);

// Maintainer gap #17063 — file I/O builtins null under strict_types must TypeError (ext/standard/file.c).
foreach (['file_get_contents', 'readfile'] as $fn) {
    try {
        $fn(null);
        echo $fn, "=uncaught\n";
    } catch (TypeError $e) {
        echo $fn, '=TypeError', "\n";
    } catch (Throwable $e) {
        echo $fn, '=', get_class($e), "\n";
    }
}

try {
    file_put_contents(null, 'x');
    echo "file_put_contents=uncaught\n";
} catch (TypeError $e) {
    echo "file_put_contents=TypeError\n";
} catch (Throwable $e) {
    echo 'file_put_contents=', get_class($e), "\n";
}
