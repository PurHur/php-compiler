<?php
$class = 'stdClass';
$o = new $class;
echo get_class($o), "\n";
$class2 = 'Exception';
try {
    throw new $class2('boom');
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
