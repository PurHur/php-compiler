<?php
$rf = new ReflectionFunction('is_uploaded_file');
echo $rf->getParameters()[0]->getName(), "\n";
try {
    var_dump(is_uploaded_file(filename: '/nope'));
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    var_dump(is_uploaded_file(path: '/nope'));
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
