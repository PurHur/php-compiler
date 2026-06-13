<?php
// Compile-only (#6778): SplObserver / SplSubject builtin interfaces on AOT user-script path.
var_export(interface_exists('SplObserver', false));
var_export(interface_exists('SplSubject', false));

interface SmokeObserver extends SplObserver {
    public function update(SplSubject $subject): void;
}

class SmokeSubject implements SplSubject {
    public function attach(SplObserver $observer): void {}
    public function detach(SplObserver $observer): void {}
    public function notify(): void {}
}
