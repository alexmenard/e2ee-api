<?php

namespace App\Support;

class Request
{
    private $body;
    private $attributes = [];
    private $headers = [];

    public function __construct()
    {
        $raw = file_get_contents('php://input');
        $this->body = json_decode($raw, true) ?: [];

        // Normalize headers
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $this->headers[$name] = $value;
            }
        }

        // Content-Type isn't HTTP_*
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $this->headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }
    }

    public function getMethod()
    {
        return $_SERVER['REQUEST_METHOD'];
    }

    public function getPath()
    {
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $uri = rtrim($uri, '/');
        return $uri === '' ? '/' : $uri;
    }

    public function getBody()
    {
        return $this->body;
    }

    public function header($name, $default = null)
    {
        $key = strtolower($name);
        return isset($this->headers[$key]) ? $this->headers[$key] : $default;
    }

    public function setAttribute($key, $value)
    {
        $this->attributes[$key] = $value;
    }

    public function getAttribute($key, $default = null)
    {
        return isset($this->attributes[$key]) ? $this->attributes[$key] : $default;
    }
}
