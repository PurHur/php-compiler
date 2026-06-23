<?php
declare(strict_types=1);

class C {
    public function m(): void {
        $trace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT);
        var_export(isset($trace[0]['object']));
        echo "\n";
        if (isset($trace[0]['object'])) {
            var_export($trace[0]['object'] instanceof self);
        }
    }
}

(new C())->m();
