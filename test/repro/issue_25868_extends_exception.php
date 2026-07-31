<?php
/**
 * #25868 — user class extends Exception/Error must match Zend (no Throwable::getmessage Fatal).
 * php-src: Zend/zend_exceptions.stub.php, Zend/zend_inheritance.c
 */
echo "named_Exception=";
try {
    eval('class E_Ex extends Exception { public function x(): int { return 1; } }');
    echo (new E_Ex("m"))->x() . "|" . (new E_Ex("m"))->getMessage();
} catch (Throwable $e) {
    echo get_class($e) . ":" . $e->getMessage();
}
echo "\n";
echo "named_Error=";
try {
    eval('class E_Err extends Error { public function x(): int { return 1; } }');
    echo (new E_Err("m"))->x() . "|" . (new E_Err("m"))->getMessage();
} catch (Throwable $e) {
    echo get_class($e) . ":" . $e->getMessage();
}
echo "\n";
echo "named_RuntimeException=";
try {
    eval('class E_Re extends RuntimeException { public function x(): int { return 1; } }');
    echo (new E_Re("m"))->x() . "|" . (new E_Re("m"))->getMessage();
} catch (Throwable $e) {
    echo get_class($e) . ":" . $e->getMessage();
}
echo "\n";
echo "anon_Exception=";
try {
    $o = new class("m") extends Exception {
        public function x(): int
        {
            return 1;
        }
    };
    echo $o->x() . "|" . $o->getMessage();
} catch (Throwable $e) {
    echo get_class($e) . ":" . $e->getMessage();
}
echo "\n";
