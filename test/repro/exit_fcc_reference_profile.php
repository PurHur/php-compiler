<?php
foreach (['exit', 'die'] as $c) {
    try {
        $f = $c(...);
        echo $c, '=fcc:', is_callable($f) ? 'yes' : 'no', "\n";
    } catch (Throwable $e) {
        echo $c, '=', get_class($e), ':', $e->getMessage(), "\n";
    }
}
try {
    eval('exit(status: 0);');
    echo "named_survived\n";
} catch (ParseError $e) {
    echo 'named_parse:', $e->getMessage(), "\n";
}
