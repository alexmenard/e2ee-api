<?php

namespace App\Support;

class Response
{
    private array $data;
    private int $status;

    public function __construct(array $data, int $status = 200)
    {
        $this->data = $data;
        $this->status = $status;
    }

    public static function json(array $data, int $status = 200): self
    {
        return new self($data, $status);
    }

    public static function error(string $message, int $status): self
    {
        return new self(['error' => $message], $status);
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: application/json');
        echo json_encode($this->data);
    }
}
