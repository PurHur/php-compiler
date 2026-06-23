--TEST--
Stdlib: debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT) includes object frame key (#9117, basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

class C {
    public function m(): void {
        $trace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT);
        echo isset($trace[0]['object']) ? 'has_object' : 'no_object', "\n";
        if (isset($trace[0]['object'])) {
            echo $trace[0]['object'] instanceof self ? 'is_self' : 'not_self', "\n";
        }
    }
}

(new C())->m();
--EXPECT--
has_object
is_self
