<?php
class RouterosAPI {
    var $debug = false;
    var $connected = false;
    var $port = 8728;
    var $timeout = 3;
    var $attempts = 1;
    var $delay = 0;
    var $socket;
    var $error_no;
    var $error_str;

    function connect($host, $login, $password) {
        for ($attempt = 1; $attempt <= $this->attempts; $attempt++) {
            $this->connected = false;
            $this->socket = @fsockopen($host, $this->port, $this->error_no, $this->error_str, $this->timeout);
            if ($this->socket) {
                socket_set_timeout($this->socket, $this->timeout);

                // Try modern login first (RouterOS 6.43+)
                $this->write('/login', false);
                $this->write('=name=' . $login, false);
                $this->write('=password=' . $password);
                $response = $this->readSentence();

                if (isset($response[0]) && $response[0] == "!done") {
                    $hasChallenge = false;
                    foreach ($response as $line) {
                        if (strpos($line, '=ret=') === 0) {
                            $hasChallenge = true;
                            break;
                        }
                    }
                    if (!$hasChallenge) {
                        $this->connected = true;
                        return true;
                    }
                }

                // Modern login didn't work, try legacy (pre-6.43)
                @fclose($this->socket);
                $this->socket = @fsockopen($host, $this->port, $this->error_no, $this->error_str, $this->timeout);
                if ($this->socket) {
                    socket_set_timeout($this->socket, $this->timeout);
                    $this->write('/login');
                    $response = $this->readSentence();
                    if (isset($response[0]) && $response[0] == "!done") {
                        $matches = array();
                        if (preg_match('/ret=(.*)/', $response[1], $matches)) {
                            $this->write('/login', false);
                            $this->write('=name=' . $login, false);
                            $this->write('=response=00' . md5(chr(0) . $password . pack('H*', $matches[1])));
                            $response = $this->readSentence();
                            if (isset($response[0]) && $response[0] == "!done") {
                                $this->connected = true;
                                return true;
                            }
                        }
                    }
                    @fclose($this->socket);
                }
            }
            if ($this->delay > 0) {
                sleep($this->delay);
            }
        }
        return false;
    }

    function disconnect() {
        if ($this->socket) {
            @fclose($this->socket);
        }
        $this->connected = false;
    }

    function write($command, $param2 = true) {
        if ($command) {
            $data = explode("\n", $command);
            foreach ($data as $com) {
                $this->writeWord($com);
            }
            if ($param2) {
                $this->writeWord('');
            }
            return true;
        } else {
            return false;
        }
    }

    function writeWord($word) {
        $length = strlen($word);
        if ($length < 128) {
            fwrite($this->socket, chr($length));
        } else if ($length < 16384) {
            $length += 0x8000;
            fwrite($this->socket, chr(($length >> 8) & 0xFF));
            fwrite($this->socket, chr($length & 0xFF));
        } else if ($length < 2097152) {
            $length += 0xC00000;
            fwrite($this->socket, chr(($length >> 16) & 0xFF));
            fwrite($this->socket, chr(($length >> 8) & 0xFF));
            fwrite($this->socket, chr($length & 0xFF));
        }
        fwrite($this->socket, $word);
    }

    /**
     * Read a single sentence (words until zero-length word)
     */
    function readSentence() {
        $res = array();
        while (true) {
            $byte = ord(fread($this->socket, 1));
            $length = 0;
            if ($byte & 128) {
                if (($byte & 192) == 128) {
                    $length = (($byte & 63) << 8) + ord(fread($this->socket, 1));
                }
            } else {
                $length = $byte;
            }
            if ($length > 0) {
                $_res = "";
                while (strlen($_res) < $length) {
                    $_res .= fread($this->socket, $length - strlen($_res));
                }
                $res[] = $_res;
            } else {
                break;
            }
        }
        return $res;
    }

    /**
     * Read complete API response: all sentences until !done or !trap
     * Returns parsed array of records
     */
    function read() {
        $allWords = array();
        while (true) {
            $sentence = $this->readSentence();
            if (empty($sentence)) {
                break;
            }
            foreach ($sentence as $word) {
                $allWords[] = $word;
            }
            // Stop when we hit !done or !trap or !fatal
            if (isset($sentence[0]) && ($sentence[0] == '!done' || $sentence[0] == '!trap' || $sentence[0] == '!fatal')) {
                break;
            }
        }
        return $this->parseResponse($allWords);
    }

    function parseResponse($response) {
        $result = array();
        $i = 0;
        foreach ($response as $line) {
            if (strpos($line, "!re") === 0 || strpos($line, "!done") === 0) {
                $i++;
            } else if (strpos($line, "=") === 0) {
                $value = explode("=", $line, 3);
                if (isset($value[2])) {
                    $result[$i - 1][$value[1]] = $value[2];
                }
            }
        }
        return $result;
    }
}
?>
