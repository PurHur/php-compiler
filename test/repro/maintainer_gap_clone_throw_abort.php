<?php
class T {
    public function __clone(): void { throw new Exception('no'); }
}
$a = new T();
$ok = false;
try { $b = clone $a; $ok = true; } catch (Exception $e) {}
var_export($ok);
