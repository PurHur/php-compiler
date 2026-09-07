<?php
// b2c9: named method call with Closure from inside method
interface R { public function write($s); public function body(); }
class Resp implements R {
    public $b = '';
    public function write($s) { $this->b .= $s; return $this; }
    public function body() { return $this->b; }
}
class Strategy {
    public function invoke($callable, $request, R $response, array $args): R {
        return $callable($request, $response, $args);
    }
}
class Route {
    public function handle($request): R {
        $callable = function ($req, $res, $args) {
            $res->write('hello');
            return $res;
        };
        $strategy = new Strategy();
        $response = new Resp();
        return $strategy->invoke($callable, $request, $response, []);
    }
}
echo (new Route())->handle(null)->body() . "\n";
