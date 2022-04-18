<?php

require_once __DIR__ . '/vendor/autoload.php';

SessionUtils::sessionStart();
session_destroy();

// cleanup all cookies
if (isset($_SERVER['HTTP_COOKIE'])) {
    $cookies = explode(';', $_SERVER['HTTP_COOKIE']);
    foreach($cookies as $cookie) {
        $parts = explode('=', $cookie);
        $name = trim($parts[0]);
        setcookie($name, '', time()-1000);
        setcookie($name, '', time()-1000, BASE_PATH);
    }
}

// redirect to home page
$origin = dirname(HttpUtils::fullUrl($_SERVER));
header("Location: $origin");
