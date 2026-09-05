<?php
declare(strict_types=1);
final class RF36382 {}
final class CR36382 {}
final class RC36382 {
    public function __construct(RF36382 $rf, CR36382 $cr, ?object $container = null) { echo "RC\n"; }
}
class Proxy36382 {
    public RC36382 $rc;
    public function __construct(RF36382 $rf, CR36382 $cr, ?object $container = null, ?RC36382 $rc = null) {
        echo "Proxy\n";
        $this->rc = $rc ?? new RC36382($rf, $cr, $container);
        echo "ProxyDone\n";
    }
}
final class AppC extends Proxy36382 {
    public function __construct(RF36382 $rf) {
        echo "C\n";
        parent::__construct($rf, new CR36382()); // omit nullable
        echo "CDone\n";
    }
}
final class AppD extends Proxy36382 {
    public function __construct(RF36382 $rf) {
        echo "D\n";
        $c = null;
        $r = null;
        parent::__construct($rf, new CR36382(), $c, $r);
        echo "DDone\n";
    }
}
echo "1\n"; new AppC(new RF36382()); echo "1ok\n";
echo "2\n"; new AppD(new RF36382()); echo "2ok\n";
