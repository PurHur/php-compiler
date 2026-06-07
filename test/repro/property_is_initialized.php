<?php
class Box {
    public int $count;
    public function probe(): bool {
        return $this->propertyIsInitialized('count');
    }
}
$b = new Box();
var_export($b->probe());
$b->count = 1;
var_export($b->probe());
