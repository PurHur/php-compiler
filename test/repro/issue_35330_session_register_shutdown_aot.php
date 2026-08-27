<?php

declare(strict_types=1);

// #35330 leftover #4873 — session_register_shutdown JIT/AOT
session_id('abcdefghijklmnop');
session_start();
$_SESSION['x'] = 42;
$r = session_register_shutdown();
var_dump($r);
echo 'reg', "\n";
