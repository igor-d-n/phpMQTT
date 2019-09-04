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
    $c = 10000;
    $t1 = microtime(1);
    for($i=0; $i<$c; $i++){
//        $now = DateTime::createFromFormat('U.u', microtime(true));
//        $mqtt->publish("example/publishtest", "$i Hello World at " . $now->format("m-d-Y H:i:s.u"));
        $mqtt->publish("example/publishtest", "$i Hello World");
    }
    $tF = round((microtime(1) - $t1)*1000, 2);
    echo "published $c messages in {$tF}ms\n";
} catch (ConnectException $e){
    echo "Connection problems: ". $e->getMessage();
}