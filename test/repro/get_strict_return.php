<?php
declare(strict_types=1);
class C { public function __get(string $n): int { return "42"; } }
try { var_export((new C)->x); echo PHP_EOL; } catch (Throwable $e) { echo get_class($e), ":", $e->getMessage(), PHP_EOL; }
