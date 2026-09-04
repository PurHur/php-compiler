<?php
class Creator {
    public function fromGlobals() { echo "ok\n"; return 1; }
}
class Wrapper {
    protected $serverRequestCreator;
    protected string $serverRequestCreatorMethod;
    public function __construct($c, string $m) {
        $this->serverRequestCreator = $c;
        $this->serverRequestCreatorMethod = $m;
    }
    public function run() {
        $callable = [$this->serverRequestCreator, $this->serverRequestCreatorMethod];
        return (Closure::fromCallable($callable))();
    }
}
(new Wrapper(new Creator(), 'fromGlobals'))->run();
