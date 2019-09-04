<?php
require "../Publisher.php";
require "../ConnectException.php";

use SimpleMQTT\Publisher;
use SimpleMQTT\ConnectException;

$server = "127.0.0.1";
$port = 1883;
$username = "admin";
$password = "admin";
$clientId = 'someUniqID'; // optional. Will be uniq if not specified

$mqtt = new Publisher($server, $port, $clientId);

try{
    $mqtt->connect($username, $password);
    for($i=0; $i<10000; $i++){
        $now = DateTime::createFromFormat('U.u', microtime(true));
        $mqtt->publish("example/publishtest", "$i Hello World at " . $now->format("m-d-Y H:i:s.u"));
    }
} catch (ConnectException $e){
    echo "Connection problems: ". $e->getMessage();
}