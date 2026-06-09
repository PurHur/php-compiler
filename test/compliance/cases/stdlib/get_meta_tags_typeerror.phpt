--TEST--
stdlib get_meta_tags() — TypeError for bad arg types under strict_types (#4608)
--FILE--
<?php
declare(strict_types=1);
try {
    get_meta_tags(123);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    get_meta_tags('/dev/null', 'yes');
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: get_meta_tags(): Argument #1 ($filename) must be of type string, int given
TypeError: get_meta_tags(): Argument #2 ($use_include_path) must be of type bool, string given
