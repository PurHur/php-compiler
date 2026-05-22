--TEST--
Language: magic constants __NAMESPACE__ and __CLASS__ in namespaced code (#199)
--FILE--
<?php
namespace App\Web;

echo __NAMESPACE__, "\n";

class Home {
    public function fqcn(): string {
        return __CLASS__;
    }
}

echo (new Home)->fqcn(), "\n";
--EXPECT--
App\Web
App\Web\Home
