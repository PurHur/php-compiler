<?php
declare(strict_types=1);
class C { public function __isset(string $n): bool { return 1; } }
try { var_export(isset((new C)->foo)); echo PHP_EOL; } catch (Throwable $e) { echo get_class($e), ":", $e->getMessage(), PHP_EOL; }
