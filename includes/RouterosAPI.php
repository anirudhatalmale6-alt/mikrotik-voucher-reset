<?php
/**
 * RouterOS API Client
 *
 * Supports both legacy (pre-6.43 challenge-response) and modern (plain text) login.
 * Optimized for fast failure: 3-second timeout, single attempt.
 */
class RouterosAPI {

    private bool $debug = false;
    private bool $connected = false;
    private int $port = 8728;
    private int $timeout = 3;
    private int $attempts = 1;
    private $socket = null;
    private int $errorNo = 0;
    private string $errorStr = '';

    public function setDebug(bool $debug): void {
        $this->debug = $debug;
    }

    public function setTimeout(int $timeout): void {
        $this->timeout = $timeout;
    }

    public function setPort(int $port): void {
        $this->port = $port;
    }

    public function setAttempts(int $attempts): void {
        $this->attempts = $attempts;
    }

    public function isConnected(): bool {
        return $this->connected;
    }

    public function getError(): string {
        return $this->errorStr;
    }

    /**
     * Connect and authenticate with the RouterOS device.
     * Supports both legacy (pre-6.43) and modern login methods.
     */
    public function connect(string $host, string $login, string $password): bool {
        for ($attempt = 1; $attempt <= $this->attempts; $attempt++) {
            $this->connected = false;
            $this->socket = @fsockopen($host, $this->port, $this->errorNo, $this->errorStr, $this->timeout);

            if (!$this->socket) {
                $this->log("Connection failed to {$host}:{$this->port} - {$this->errorStr}");
                continue;
            }

            stream_set_timeout($this->socket, $this->timeout);

            // Try modern login first (RouterOS 6.43+)
            if ($this->loginModern($login, $password)) {
                $this->connected = true;
                return true;
            }

            // If modern login returns a challenge hash, use legacy method
            // Close and reconnect for legacy attempt
            @fclose($this->socket);
            $this->socket = @fsockopen($host, $this->port, $this->errorNo, $this->errorStr, $this->timeout);

            if (!$this->socket) {
                continue;
            }

            stream_set_timeout($this->socket, $this->timeout);

            if ($this->loginLegacy($login, $password)) {
                $this->connected = true;
                return true;
            }

            @fclose($this->socket);
            $this->socket = null;
        }

        return false;
    }

    /**
     * Modern login (RouterOS 6.43+): plain text credentials
     */
    private function loginModern(string $login, string $password): bool {
        $this->write('/login', false);
        $this->write('=name=' . $login, false);
        $this->write('=password=' . $password);
        $response = $this->readRaw();

        if (isset($response[0]) && $response[0] === '!done') {
            // Check if this is truly done (no challenge hash returned)
            foreach ($response as $line) {
                if (strpos($line, '=ret=') === 0) {
                    // Legacy system returned a hash - modern login not supported
                    return false;
                }
            }
            return true;
        }

        return false;
    }

    /**
     * Legacy login (pre-6.43): challenge-response with MD5
     */
    private function loginLegacy(string $login, string $password): bool {
        $this->write('/login');
        $response = $this->readRaw();

        if (!isset($response[0]) || $response[0] !== '!done') {
            return false;
        }

        // Extract challenge hash
        $hash = null;
        foreach ($response as $line) {
            if (preg_match('/^=ret=(.+)$/', $line, $matches)) {
                $hash = $matches[1];
                break;
            }
        }

        if ($hash === null) {
            return false;
        }

        // Send challenge response
        $challengeResponse = '00' . md5(chr(0) . $password . pack('H*', $hash));

        $this->write('/login', false);
        $this->write('=name=' . $login, false);
        $this->write('=response=' . $challengeResponse);
        $response = $this->readRaw();

        return isset($response[0]) && $response[0] === '!done';
    }

    /**
     * Disconnect from the router
     */
    public function disconnect(): void {
        if ($this->socket) {
            @fclose($this->socket);
            $this->socket = null;
        }
        $this->connected = false;
    }

    /**
     * Send a command (or multiple lines). If $endSentence is true, sends
     * an empty word to mark end of sentence.
     */
    public function write(string $command, bool $endSentence = true): bool {
        if (empty($command)) {
            return false;
        }

        $lines = explode("\n", $command);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $this->writeWord($line);
            }
        }

        if ($endSentence) {
            $this->writeWord('');
        }

        return true;
    }

    /**
     * Write a single API word (length-prefixed)
     */
    private function writeWord(string $word): void {
        $length = strlen($word);

        if ($length < 0x80) {
            fwrite($this->socket, chr($length));
        } elseif ($length < 0x4000) {
            $length |= 0x8000;
            fwrite($this->socket, chr(($length >> 8) & 0xFF));
            fwrite($this->socket, chr($length & 0xFF));
        } elseif ($length < 0x200000) {
            $length |= 0xC00000;
            fwrite($this->socket, chr(($length >> 16) & 0xFF));
            fwrite($this->socket, chr(($length >> 8) & 0xFF));
            fwrite($this->socket, chr($length & 0xFF));
        } elseif ($length < 0x10000000) {
            $length |= 0xE0000000;
            fwrite($this->socket, chr(($length >> 24) & 0xFF));
            fwrite($this->socket, chr(($length >> 16) & 0xFF));
            fwrite($this->socket, chr(($length >> 8) & 0xFF));
            fwrite($this->socket, chr($length & 0xFF));
        } else {
            fwrite($this->socket, chr(0xF0));
            fwrite($this->socket, chr(($length >> 24) & 0xFF));
            fwrite($this->socket, chr(($length >> 16) & 0xFF));
            fwrite($this->socket, chr(($length >> 8) & 0xFF));
            fwrite($this->socket, chr($length & 0xFF));
        }

        if ($length > 0) {
            fwrite($this->socket, $word);
        }

        $this->log(">>> {$word}");
    }

    /**
     * Read raw response words from the socket
     */
    private function readRaw(): array {
        $response = [];

        while (true) {
            $byte = @fread($this->socket, 1);
            if ($byte === false || $byte === '') {
                break;
            }

            $byte = ord($byte);
            $length = 0;

            if ($byte < 0x80) {
                $length = $byte;
            } elseif (($byte & 0xC0) === 0x80) {
                $length = (($byte & 0x3F) << 8) + ord(fread($this->socket, 1));
            } elseif (($byte & 0xE0) === 0xC0) {
                $length = (($byte & 0x1F) << 16)
                    + (ord(fread($this->socket, 1)) << 8)
                    + ord(fread($this->socket, 1));
            } elseif (($byte & 0xF0) === 0xE0) {
                $length = (($byte & 0x0F) << 24)
                    + (ord(fread($this->socket, 1)) << 16)
                    + (ord(fread($this->socket, 1)) << 8)
                    + ord(fread($this->socket, 1));
            }

            if ($length > 0) {
                $word = '';
                $remaining = $length;
                while ($remaining > 0) {
                    $chunk = fread($this->socket, $remaining);
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $word .= $chunk;
                    $remaining -= strlen($chunk);
                }
                $response[] = $word;
                $this->log("<<< {$word}");
            } else {
                // Empty word = end of sentence
                break;
            }
        }

        return $response;
    }

    /**
     * Read and parse response into associative arrays.
     * Returns an array of items, each item being an associative array of key=>value.
     */
    public function read(): array {
        $raw = $this->readRaw();
        return $this->parseResponse($raw);
    }

    /**
     * Parse raw API response into structured data
     */
    private function parseResponse(array $response): array {
        $result = [];
        $current = -1;

        foreach ($response as $line) {
            if ($line === '!re' || $line === '!done') {
                $current++;
                $result[$current] = [];
            } elseif ($line === '!trap' || $line === '!fatal') {
                $current++;
                $result[$current] = ['!type' => $line];
            } elseif (strpos($line, '=') === 0) {
                // Parse =key=value format
                $parts = explode('=', substr($line, 1), 2);
                if (count($parts) === 2 && $current >= 0) {
                    $result[$current][$parts[0]] = $parts[1];
                }
            }
        }

        // Remove empty !done entries at the end
        if (!empty($result)) {
            $last = end($result);
            if (empty($last)) {
                array_pop($result);
            }
        }

        return $result;
    }

    /**
     * Execute a command and return parsed results in one call
     */
    public function command(string $cmd, array $params = []): array {
        $this->write($cmd, empty($params));

        foreach ($params as $i => $param) {
            $isLast = ($i === count($params) - 1);
            $this->write($param, $isLast);
        }

        return $this->read();
    }

    /**
     * Debug logging
     */
    private function log(string $message): void {
        if ($this->debug) {
            error_log("[RouterosAPI] {$message}");
        }
    }

    public function __destruct() {
        if ($this->connected) {
            $this->disconnect();
        }
    }
}
