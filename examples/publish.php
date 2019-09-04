<?php

use SimpleMQTT\Publisher;
use SimpleMQTT\ConnectException;

$server = "mqtt.example.com";
$port = 1883;
$username = "mqttUser";
$password = "mqttPassword";
$clientId = 'someUniqID'; // optional. Will be uniq if not specified

$mqtt = new Publisher($server, $port, $clientId);

try{
    $mqtt->connect($username, $password);
    $mqtt->publish("example/publishtest", "Hello World at " . date("r"));
    $mqtt->close();
} catch (ConnectException $e){
    echo "Connection problems: ". $e->getMessage();
}