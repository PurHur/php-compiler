<?php
function inner() {
    throw new Exception('nested');
}
function outer() {
    inner();
}
outer();
