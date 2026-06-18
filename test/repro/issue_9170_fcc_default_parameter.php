<?php
class C {
    public function f(Closure $c = strlen(...)): int {
        return $c('abc');
    }
}
echo (new C)->f(), "\n";
