<?php
/** Repro #28184 — serialize omits virtual hooks; emits mangled backing only (php-src-strict). */
class H {
    public string $x {
        get => 'g';
        set { }
    }
}
echo serialize(new H), PHP_EOL;

class H2 {
    private string $_x = 'ab';
    public string $x {
        get => $this->_x;
        set => $this->_x = $value;
    }
}
echo str_replace("\0", '\\0', serialize(new H2)), PHP_EOL;

class P {
    public string $plain = 'p';
    public string $x {
        get => 'g';
        set { }
    }
}
echo serialize(new P), PHP_EOL;

echo json_encode(new H), PHP_EOL;
