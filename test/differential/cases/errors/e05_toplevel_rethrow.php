<?php
try {
    throw new Exception('x');
} catch (Exception $e) {
    throw new Exception('y');
}
