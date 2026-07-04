<?php
declare(strict_types=1);

class C {
    public function m(): void {
        $trace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT);
        echo isset($trace[0]['object']) ? 'true' : 'false', "\n";
        echo $trace[0]['object'] instanceof self ? 'true' : 'false', "\n";
    }
}

(new C())->m();
