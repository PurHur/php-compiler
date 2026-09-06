<?php
declare(strict_types=1);
interface DI {}
final class D implements DI { public string $id; public function __construct(string $id='x'){ $this->id=$id; } }
final class RR {
    public DI $dispatcher;
    public function __construct(?DI $dispatcher = null) {
        $this->dispatcher = $dispatcher instanceof DI ? $dispatcher : new D('inner');
    }
}
final class App {
    public RR $rr;
    public function __construct(?RR $rr = null) {
        $this->rr = $rr instanceof RR ? $rr : new RR();
    }
}
final class Factory {
    public static function create(?RR $rr = null): App {
        $resolved = $rr ?? null;
        return new App($resolved);
    }
}
$app = Factory::create();
echo $app->rr->dispatcher instanceof D ? $app->rr->dispatcher->id : '?', "\n";
echo "ok\n";
