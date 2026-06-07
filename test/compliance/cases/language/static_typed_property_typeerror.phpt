--TEST--
Static typed property assignment TypeError names class and property (issue #7368)
--FILE--
<?php
declare(strict_types=1);
class C { public static string $s = 'a'; }
try { C::$s = 1; } catch (Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
--EXPECT--
TypeError: Cannot assign int to property C::$s of type string
