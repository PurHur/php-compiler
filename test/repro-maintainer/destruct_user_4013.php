<?php
class D {
    public function __destruct() {
        echo "bye\n";
    }
}
new D();
echo "end\n";
