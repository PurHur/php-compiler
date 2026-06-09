--TEST--
stdlib filesystem path builtins phase 2 JIT — enum path operands TypeError (#6205)
--FILE--
<?php
enum PathEnum: string { case A = 'x'; }

$fns = [
    'filesize',
    'touch',
    'fopen',
    'file_put_contents',
    'sha1_file',
    'md5_file',
];

foreach ($fns as $fn) {
    try {
        if ('fopen' === $fn) {
            $fn(PathEnum::A, 'r');
        } elseif ('file_put_contents' === $fn) {
            $fn(PathEnum::A, 'data');
        } else {
            $fn(PathEnum::A);
        }
        echo "{$fn} uncaught\n";
    } catch (TypeError $e) {
        echo "{$fn} TypeError\n";
    } catch (LogicException $e) {
        echo "{$fn} LogicException\n";
    }
}
--EXPECT--
filesize TypeError
touch TypeError
fopen TypeError
file_put_contents TypeError
sha1_file TypeError
md5_file TypeError
