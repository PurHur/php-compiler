<?php
declare(strict_types=1);
class C {
    public function __call(string $n, array $a): string { return 5; }
    public static function __callStatic(string $n, array $a): string { return 5; }
}
try { echo (new C)->foo(), PHP_EOL; } catch (Throwable $e) { echo get_class($e), ':', $e->getMessage(), PHP_EOL; }
try { echo C::bar(), PHP_EOL; } catch (Throwable $e) { echo get_class($e), ':', $e->getMessage(), PHP_EOL; }
