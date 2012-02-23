<?php

class CouchWorkflow_CouchClient
{

    private $host;
    private $port;
    private $database;

    /**
     * @param string $host
     * @param int $port
     */
    public function __construct($host, $database, $port = 5984)
    {
        $this->host = $host;
        $this->port = $port;
        $this->database = $database;
    }

    /**
     * Sends an HTTP request to the CouchDB server.
     *
     * @param string $method
     * @param string $path
     * @param string $payload
     * @param array $params
     * @return array
     * @throws RuntimeException
     */
    public function request($method, $path, $payload = NULL, $params = array())
    {
        $socket = @fsockopen($this->host, $this->port, $errno, $errstr);

        if (!$socket) {
            throw new RuntimeException($errno . ': ' . $errstr);
        }

        $path = '/' . $this->database . $path;
        if (count($params) > 0) {
            $path .= "?";
            foreach ($params AS $k => $v) {
                $params[$k] = (is_array($v)) ? json_encode($v) : $v;
            }
            $path .= http_build_query($params);
        }

        $request = $method . ' ' . $path . " HTTP/1.0\r\nHost: localhost\r\n";

        if ($payload !== NULL) {
            $payload = json_encode($payload);
            $request .= 'Content-Length: ' . strlen($payload) . "\r\n\r\n";
            $request .= $payload;
        }

        $request .= "\r\n";
        fwrite($socket, $request);

        $buffer = '';

        while (!feof($socket)) {
            $buffer .= fgets($socket);
        }

        if (strlen($buffer) == 0) {
            throw new RuntimeException('Illegal Query to CouchDB resulted in empty response.');
        }

        list($headers, $body) = explode("\r\n\r\n", $buffer);

        if (preg_match('(HTTP\/1\.[01]+ ([0-9]{3}))', $headers, $match)) {
            $code = (int)$match[1];
        }

        if (!$code) {
            throw new UnexpectedValueException("No HTTP Code was found from a " . $method . " request against the CouchDB " . $path);
        } else if ($code > 400) {
            throw CouchWorkflow_CouchHttpException::factory($code, $method, $path);
        }

        return json_decode($body, true);
    }

}