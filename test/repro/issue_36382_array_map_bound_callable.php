<?php
/**
 * #36382 — array_map([$this, 'method'], …) AOT (FastRoute DataGenerator shape).
 * php-src: ext/standard/array.c php_array_map()
 */
abstract class Base {
    abstract protected function processChunk($chunk);

    public function generate(array $chunks): array {
        return array_map([$this, 'processChunk'], $chunks);
    }
}

class Concrete extends Base {
    protected function processChunk($chunk) {
        return 'P:'.implode(',', $chunk);
    }
}

$g = new Concrete();
echo implode('|', $g->generate([[1, 2], [3]])), "\n";
