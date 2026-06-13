--TEST--
stdlib SplObserver / SplSubject — builtin interface registration (#6778, ext/spl/spl_observer.c)
--FILE--
<?php
var_export(interface_exists('SplObserver', false));
echo "\n";
var_export(interface_exists('SplSubject', false));
echo "\n";

interface MyObserver extends SplObserver {
    public function update(SplSubject $subject): void;
}

class MySubject implements SplSubject {
    public function attach(SplObserver $observer): void {}
    public function detach(SplObserver $observer): void {}
    public function notify(): void {}
}

echo interface_exists('MyObserver', false) ? "my_observer\n" : "no_my_observer\n";
echo class_exists('MySubject', false) ? "my_subject\n" : "no_my_subject\n";
?>
--EXPECT--
true
true
my_observer
my_subject
