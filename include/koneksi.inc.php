<?php

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

$dotenv = new \Dotenv\Dotenv(dirname(__DIR__));
$dotenv->load();
$dotenv->required(array('DB_HOST', 'DB_NAME', 'DB_USER'))->notEmpty();

$host = $_ENV['DB_HOST'];
$port = isset($_ENV['DB_PORT']) ? $_ENV['DB_PORT'] : 5432;
$databaseName = $_ENV['DB_NAME'];
$username = $_ENV['DB_USER'];
$password = isset($_ENV['DB_PASS']) ? $_ENV['DB_PASS'] : '';

$db = pg_connect("host=$host port=$port dbname=$databaseName user=$username password=$password");

if (!$db) {
    print("Connection Failed");
    exit;
}

function nmper()
{
    $nmcomp1 = "PT Arwana Citramulia Tbk";
    return $nmcomp1;
}

function almper1()
{
    $almper = "";
    return $almper;
}

function almper2()
{
    $almper = "";
    return $almper;
}

function ttdpo()
{
    $nm = "";
    return $nm;
}

function ttdsj()
{
    $nm = "";
    return $nm;
}
