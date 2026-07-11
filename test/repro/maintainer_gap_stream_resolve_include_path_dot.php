<?php

declare(strict_types=1);

$cwd = getcwd();
if (false === $cwd) {
    echo "skip: getcwd unavailable\n";
    exit(0);
}

$dot = stream_resolve_include_path('.');
if ($dot !== $cwd) {
    echo "fail: dot expected '".$cwd."' got ".var_export($dot, true)."\n";
    exit(1);
}

$empty = stream_resolve_include_path('');
if ($empty !== $cwd) {
    echo "fail: empty expected '".$cwd."' got ".var_export($empty, true)."\n";
    exit(1);
}

echo "ok\n";
