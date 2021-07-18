<?php

namespace SimpleMQTT;

/*
	A simple php class to connect and publish to an MQTT broker
*/


class Publisher
{

    protected $socket;              // holds the socket
    protected $msgId = 1;           // counter for message id
    protected $keepAlive = 10;      // default keepalive timer
    protected $socketTimeout = 5;   // timeout for waiting for socket tcp connection
    protected $debug = false;       // should output debug messages
    protected $address;             // broker address
    protected $port;                // broker port
    protected $clientId;            // client id sent to broker 
    protected $will;                // stores the will of the client
    protected $username;            // stores username
    protected $password;            // stores password
    protected $connected = false;
    protected $cafile;

    function __construct($address, $port, $clientId = null, $cafile = null)
    {
        if ($clientId === null) {
            $clientId = uniqid('phpMQTT_', true);
        }
        $this->address = $address;
        $this->port = $port;
        $this->clientId = $clientId;
        $this->cafile = $cafile;
    }
    
    function __destruct()
    {
        if($this->connected){
            $this->close();
        }
    }

    /**
     * @param null $username
     * @param null $password
     * @param null $will array ['topic' => ..., 'msg' => ..., 'retain' => 0|1, 'qos'=> 0|1|2]
     * @param bool $clean send a clean session flag
     * @throws ConnectException
     */
    public function connect($username = null, $password = null, $will = null, $clean = true)
    {
        if ($will) $this->will = $will;
        if ($username) $this->username = $username;
        if ($password) $this->password = $password;

        if ($this->cafile) {
            $socketContext = stream_context_create(["ssl" => [
                "verify_peer_name" => true,
                "cafile" => $this->cafile
            ]]);
            $this->socket = stream_socket_client("tls://" . $this->address . ":" . $this->port, $errno,
                $errStr, $this->socketTimeout, STREAM_CLIENT_CONNECT, $socketContext);
        } else {
            $this->socket = stream_socket_client("tcp://" . $this->address . ":" . $this->port, $errno,
                $errStr, $this->socketTimeout, STREAM_CLIENT_CONNECT);
        }

        if ($this->socket === false) {
            $this->connected = false;
            throw new ConnectException("stream_socket_create() $errno, $errStr \n");
        }

        stream_set_timeout($this->socket, 5);
//        stream_set_blocking($this->socket, 0);

        $i = 0;

        $buffer = chr(0x00);
        $i++;
        $buffer .= chr(0x06);
        $i++;
        $buffer .= chr(0x4d);
        $i++;
        $buffer .= chr(0x51);
        $i++;
        $buffer .= chr(0x49);
        $i++;
        $buffer .= chr(0x73);
        $i++;
        $buffer .= chr(0x64);
        $i++;
        $buffer .= chr(0x70);
        $i++;
        $buffer .= chr(0x03);
        $i++;

        //No Will
        $var = 0;
        if ($clean) $var += 2;

        //Add will info to header
        if ($this->will !== null) {
            $var += 4; // Set will flag
            $var += ($this->will['qos'] << 3); //Set will qos
            if ($this->will['retain']) $var += 32; //Set will retain
        }

        if ($this->username !== null) $var += 128;    //Add username to header
        if ($this->password !== null) $var += 64;    //Add password to header

        $buffer .= chr($var);
        $i++;

        //Keep alive
        $buffer .= chr($this->keepAlive >> 8);
        $i++;
        $buffer .= chr($this->keepAlive & 0xff);
        $i++;

        $buffer .= $this->writeStringToBuffer($this->clientId, $i);

        //Adding will to payload
        if ($this->will !== null) {
            $buffer .= $this->writeStringToBuffer($this->will['topic'], $i);
            $buffer .= $this->writeStringToBuffer($this->will['msg'], $i);
        }

        if ($this->username) $buffer .= $this->writeStringToBuffer($this->username, $i);
        if ($this->password) $buffer .= $this->writeStringToBuffer($this->password, $i);

        $head = chr(0x10);
        $head .= chr($i);

        fwrite($this->socket, $head, 2);
        fwrite($this->socket, $buffer);

        $string = $this->read(4);

        if (ord($string[0]) >> 4 == 2 && $string[3] == chr(0)) {
            if ($this->debug) echo "Connected to Broker\n";
        } else {
            $this->connected = false;
            throw new ConnectException(sprintf("Connection failed! (Error: 0x%02x 0x%02x)\n", ord($string[0]), ord($string[3])));
        }
        $this->connected = true;
    }

    // reads in so many bytes
    protected function read($length = 8192, $nb = false)
    {
        $string = '';
        $togo = $length;
		
        if ($nb) {
            return fread($this->socket, $togo);
        }

        while (!feof($this->socket) && $togo > 0) {
            $fread = fread($this->socket, $togo);
            $string .= $fread;
            $togo = $length - strlen($string);
        }

        return $string;
    }

    // sends a proper disconnect, then closes the socket
    public function close()
    {
        $head = chr(0xe0);
        $head .= chr(0x00);
        fwrite($this->socket, $head, 2);
        stream_socket_shutdown($this->socket, STREAM_SHUT_WR);
        $this->connected = false;
    }

    // publishes $content on a $topic
    public function publish($topic, $content, $retain = 0, $qos = 0)
    {
        $i = 0;

        $buffer = $this->writeStringToBuffer($topic, $i);

        if ($qos) {
            $id = $this->msgId++;
            $buffer .= chr($id >> 8);
            $i++;
            $buffer .= chr($id % 256);
            $i++;
        }

        $buffer .= $content;
        $i += strlen($content);

        $cmd = 0x30;
        if ($qos) $cmd += $qos << 1;
        if ($retain) ++$cmd;

        $head = chr($cmd);
        $head .= $this->setMsgLength($i);

        fwrite($this->socket, $head, strlen($head));
        fwrite($this->socket, $buffer, $i);
    }

    protected function setMsgLength($len)
    {
        $string = "";
        do {
            $digit = $len % 128;
            $len >>= 7;
            // if there are more digits to encode, set the top bit of this digit
            if ($len > 0) $digit |= 0x80;
            $string .= chr($digit);
        } while ($len > 0);
        return $string;
    }

    // writes a string to a buffer
    protected function writeStringToBuffer($str, &$i)
    {
        $len = strlen($str);
        $msb = $len >> 8;
        $lsb = $len % 256;
        $ret = chr($msb);
        $ret .= chr($lsb);
        $ret .= $str;
        $i += ($len + 2);
        return $ret;
    }

}
