<?php
spl_autoload_register(function (string $class): void {
    if ($class === 'Autoloaded') {
        class Autoloaded {
            public function id(): int { return 7; }
        }
    }
});
echo (new Autoloaded())->id(), "\n";
