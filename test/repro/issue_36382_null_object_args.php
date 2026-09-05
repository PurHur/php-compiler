<?php
declare(strict_types=1);
final class RF36382 {}
final class CR36382 {}
final class RC36382 {
    public function __construct(RF36382 $rf, CR36382 $cr, ?object $c = null) {
        echo "RC\n";
    }
}
class Proxy36382 {
    public RC36382 $rc;
    public function __construct(RF36382 $rf, CR36382 $cr, ?object $c = null, ?RC36382 $rc = null) {
        echo "P1\n";
        echo "rc_is_null:", (null === $rc ? "1" : "0"), "\n";
        $this->rc = $rc ?? new RC36382($rf, $cr, $c);
        echo "P2:", get_class($this->rc), "\n";
    }
}
echo "C1\n";
$p = new Proxy36382(new RF36382(), new CR36382(), null, null);
echo "C2\n";
