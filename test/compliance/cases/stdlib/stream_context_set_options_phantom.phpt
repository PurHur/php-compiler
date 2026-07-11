--TEST--
stdlib stream_context_set_options() — not advertised on PHP 8.2 reference profile (#12597)
--FILE--
<?php
declare(strict_types=1);

echo function_exists('stream_context_set_options') ? "fn-fail\n" : "fn-ok\n";
--EXPECT--
fn-ok
