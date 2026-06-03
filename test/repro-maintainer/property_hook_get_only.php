<?php
class Box {
    private string $label = 'ok';
    public string $name {
        get => $this->label;
    }
}
$b = new Box();
echo $b->name, "\n";
try {
    $b->name = 'bad';
    echo "assigned\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
