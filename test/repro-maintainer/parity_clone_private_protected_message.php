<?php

class PrivateClone {
    private function __clone() {}
}

try {
    clone new PrivateClone();
} catch (Error $e) {
    echo 'private: ', $e->getMessage(), "\n";
}

class ProtectedBase {
    protected function __clone() {}
}

class ProtectedChild extends ProtectedBase {
}

try {
    clone new ProtectedChild();
} catch (Error $e) {
    echo 'protected: ', $e->getMessage(), "\n";
}
