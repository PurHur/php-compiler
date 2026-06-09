<?php
enum E: string { case A = '/etc/hosts'; }

$fns = [
    'filesize',
    'disk_free_space',
    'disk_total_space',
    'is_dir',
    'chmod',
    'touch',
    'fopen',
    'file_put_contents',
    'scandir',
    'readfile',
    'fileperms',
    'filemtime',
    'sha1_file',
    'md5_file',
];

foreach ($fns as $fn) {
    if (!function_exists($fn)) {
        echo "{$fn} missing\n";
        continue;
    }
    try {
        if ('fopen' === $fn) {
            $fn(E::A, 'r');
        } elseif ('chmod' === $fn) {
            $fn(E::A, 0644);
        } elseif ('touch' === $fn) {
            $fn(E::A);
        } elseif ('file_put_contents' === $fn) {
            $fn(E::A, 'x');
        } else {
            $fn(E::A);
        }
        echo "{$fn} ok\n";
    } catch (TypeError $e) {
        echo "{$fn} TypeError\n";
    } catch (LogicException $e) {
        echo "{$fn} LogicException\n";
    } catch (Throwable $e) {
        echo "{$fn} ", $e::class, "\n";
    }
}
