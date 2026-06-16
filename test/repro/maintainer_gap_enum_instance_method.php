<?php
enum E: string {
    case A = 'a';
    public function label(): string { return $this->name; }
}
try {
    echo E::A->label(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
