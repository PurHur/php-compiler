<?php
var_export(trait_exists('LazyGhostTrait'));
echo "\n";
class Svc {
    use LazyGhostTrait;
    public string $id = '';
}
echo "compiled\n";
