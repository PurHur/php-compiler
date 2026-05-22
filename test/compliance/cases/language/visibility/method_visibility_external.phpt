--TEST--
private method rejected from global scope
--FILE--
<?php
class Router {
    private function renderHome(): void {
        echo "home\n";
    }
}

$r = new Router();
$r->renderHome();
--EXPECTREGEX--
Call to private method Router::renderHome\(\)
