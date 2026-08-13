<?php
try {
    strip_tags('<a>b</a>', null, 'x');
    echo "NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    strip_tags();
    echo "NO_THROW0\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
echo strip_tags('<b>ok</b>'), "\n";
echo strip_tags('<b>ok</b>', '<b>'), "\n";
