<?php
/**
 * preg_replace_callback private method visibility vs Zend.
 */
class P {
    private function fmt($m) {
        return '['.$m[0].']';
    }

    public function run() {
        echo 'in=', preg_replace_callback('/a/', [$this, 'fmt'], 'a-a'), "\n";
    }
}
(new P())->run();
$p = new P();
try {
    echo 'out=', preg_replace_callback('/a/', [$p, 'fmt'], 'a'), "\n";
} catch (Throwable $e) {
    echo 'out=', get_class($e), ':', $e->getMessage(), "\n";
}
