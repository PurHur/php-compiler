--TEST--
stdlib filesystem path builtins — enum path operands TypeError (#5735, ext/standard/filestat.c)
--FILE--
<?php
enum PathEnum: string { case A = 'x'; }
enum LocalUnitEnum { case A; }

$fns = [
    'file_get_contents',
    'file_exists',
    'is_file',
    'is_dir',
    'is_link',
    'is_readable',
    'is_writable',
    'is_executable',
    'unlink',
    'mkdir',
    'rmdir',
];

foreach ($fns as $fn) {
    foreach (['backed' => PathEnum::A, 'unit' => LocalUnitEnum::A] as $label => $operand) {
        try {
            $fn($operand);
            echo "{$fn} {$label} uncaught\n";
        } catch (TypeError $e) {
            echo "{$fn} {$label} TypeError\n";
        } catch (LogicException $e) {
            echo "{$fn} {$label} LogicException\n";
        }
    }
}
--EXPECT--
file_get_contents backed TypeError
file_get_contents unit TypeError
file_exists backed TypeError
file_exists unit TypeError
is_file backed TypeError
is_file unit TypeError
is_dir backed TypeError
is_dir unit TypeError
is_link backed TypeError
is_link unit TypeError
is_readable backed TypeError
is_readable unit TypeError
is_writable backed TypeError
is_writable unit TypeError
is_executable backed TypeError
is_executable unit TypeError
unlink backed TypeError
unlink unit TypeError
mkdir backed TypeError
mkdir unit TypeError
rmdir backed TypeError
rmdir unit TypeError
