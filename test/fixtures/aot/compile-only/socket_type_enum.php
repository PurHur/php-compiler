<?php

declare(strict_types=1);

// AOT compile-only: SocketType enum (#7235).
var_export(enum_exists('SocketType', false));
echo "\n";
var_export(SocketType::Stream->value);
echo "\n";
