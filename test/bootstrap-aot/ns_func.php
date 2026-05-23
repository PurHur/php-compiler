<?php

declare(strict_types=1);

namespace PHPCompiler\Web;

function resolve(string $path, string $fromFile): ?string
{
    if ($path[0] === '/' || (strlen($path) > 1 && $path[1] === ':')) {
        return is_file($path) ? $path : null;
    }
    $base = dirname($fromFile);
    $candidate = $base.'/'.$path;

    return is_file($candidate) ? $candidate : null;
}

$from = __DIR__.'/deploy_path_data/templates/marker.php';
$r = resolve('marker.php', $from);
echo null === $r ? 'null' : 'ok';
echo "\n";
