<?php
declare(strict_types=1);
class C { public static string $s = 'a'; }
try { C::$s = 1; } catch (Throwable $e) { echo $e->getMessage(), "\n"; }
