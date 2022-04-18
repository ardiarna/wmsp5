<?php

function autoload_class($className) {
    $file = str_replace('\\', DIRECTORY_SEPARATOR, $className);

	require_once __DIR__ . DIRECTORY_SEPARATOR . $file . '.php';
}

spl_autoload_register('autoload_class');

// load composer library
require_once dirname(__FILE__) . '/../vendor/autoload.php';
