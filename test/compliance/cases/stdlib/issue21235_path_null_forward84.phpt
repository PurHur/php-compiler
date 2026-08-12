--TEST--
stdlib fopen/file_get_contents/getimagesize/hash_file(null) — DEP+empty-path ValueError on 8.4 (#21235, file.c/image.c/hash.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
set_error_handler(static function (int $n, string $m): bool {
    echo "DEP\n";
    return true;
});
foreach ([
    'fopen' => static fn () => fopen(null, 'r'),
    'file_get_contents' => static fn () => file_get_contents(null),
    'getimagesize' => static fn () => getimagesize(null),
    'hash_file' => static fn () => hash_file('md5', null),
] as $label => $fn) {
    try {
        $fn();
        echo $label, ": miss\n";
    } catch (TypeError $e) {
        echo $label, ':TYPEERROR:', $e->getMessage(), "\n";
    } catch (ValueError $e) {
        echo $label, ':VALUEERROR:', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
DEP
fopen:VALUEERROR:Path must not be empty
DEP
file_get_contents:VALUEERROR:Path must not be empty
DEP
getimagesize:VALUEERROR:Path cannot be empty
DEP
hash_file:VALUEERROR:Path must not be empty
