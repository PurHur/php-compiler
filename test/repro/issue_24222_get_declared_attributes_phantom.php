<?php
/** Repro #24222 — get_declared_attributes phantom: not in php-src ext/reflection/php_reflection.c. */
echo function_exists('get_declared_attributes') ? "fail\n" : "ok\n";
