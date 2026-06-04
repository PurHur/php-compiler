<?php
try {
    throw new Exception('inner');
} finally {
    throw new Exception('finally');
}
