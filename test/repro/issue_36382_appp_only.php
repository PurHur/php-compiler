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
final class AppP extends Proxy36382 {
    public function __construct(RF36382 $rf, ?CR36382 $cr = null) {
        echo "P\n";
        $cr = $cr ?? new CR36382();
        echo "Pcr\n";
        parent::__construct($rf, $cr);
        echo "PDone\n";
    }
}
new AppP(new RF36382());
echo "OK\n";
