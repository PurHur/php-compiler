<?php
// Compile-only (#5845): get_resource_type()/get_resource_id() enum-case TypeError guards for AOT.
enum I: int { case A = 1; }
try {
    get_resource_type(I::A);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    get_resource_id(I::A);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
