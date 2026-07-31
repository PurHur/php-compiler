--TEST--
Fiber::suspend($this) from instance-method closure yields object (zend_fibers.c, #25777)
--FILE--
<?php
class C {
    public function run() {
        $self = $this;
        $f = new Fiber(function () {
            $sent = Fiber::suspend($this);
            return $sent;
        });
        $v = $f->start();
        echo is_object($v) ? get_class($v) : var_export($v, true), "\n";
        echo ($v === $self) ? "same\n" : "diff\n";
        echo var_export($f->resume('ok'), true), "\n";
        echo var_export($f->getReturn(), true), "\n";
    }
}
(new C())->run();
--EXPECT--
C
same
NULL
'ok'
