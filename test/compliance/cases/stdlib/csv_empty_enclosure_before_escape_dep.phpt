--TEST--
CSV empty $enclosure ValueError before omitted-$escape DEP (#29383, ext/standard/file.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message): bool {
    echo 'DEP:', $message, "\n";

    return true;
});

foreach (['str_getcsv', 'fgetcsv', 'fputcsv'] as $name) {
    echo "== $name ==\n";
    try {
        if ('str_getcsv' === $name) {
            str_getcsv('a,b', ',', '');
        } elseif ('fgetcsv' === $name) {
            $fp = fopen('php://memory', 'r+');
            fwrite($fp, "a,b\n");
            rewind($fp);
            fgetcsv($fp, null, ',', '');
            fclose($fp);
        } else {
            $fp = fopen('php://memory', 'r+');
            fputcsv($fp, ['a', 'b'], ',', '');
            fclose($fp);
        }
        echo "no error\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}

echo "== valid_omit_escape ==\n";
$r = str_getcsv('a,b', ',', '"');
echo is_array($r) ? 'ok' : 'bad', "\n";
?>
--EXPECT--
== str_getcsv ==
ValueError:str_getcsv(): Argument #3 ($enclosure) must be a single character
== fgetcsv ==
ValueError:fgetcsv(): Argument #4 ($enclosure) must be a single character
== fputcsv ==
ValueError:fputcsv(): Argument #4 ($enclosure) must be a single character
== valid_omit_escape ==
DEP:str_getcsv(): the $escape parameter must be provided as its default value will change
ok
