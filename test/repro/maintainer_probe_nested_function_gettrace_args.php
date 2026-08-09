<?php
(function () {
    function nest($a, $b) {
        throw new Exception('x');
    }
    try {
        nest('A', 'B');
    } catch (Throwable $e) {
        echo 'exception_args=', json_encode($e->getTrace()[0]['args'] ?? null), "\n";
    }
    function nest2($a, $b) {
        $bt = debug_backtrace(0, 1);
        echo 'bt_args=', json_encode($bt[0]['args'] ?? null), "\n";
    }
    nest2('C', 'D');
})();
