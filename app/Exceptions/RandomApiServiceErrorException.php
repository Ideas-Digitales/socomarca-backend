<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RandomApiServiceErrorException extends Exception
{
    protected array $data;
    protected \Illuminate\Http\Client\Response $response;

    public function __construct(
        string $message,
        array $data,
        \Illuminate\Http\Client\Response $response
    ) {
        parent::__construct($message, $response->status());
        $this->response = $response;
        $this->data = $data;
    }

    /**
     * Report
     */
    public function report()
    {
        Log::error('Random API Error: ' . $this->getMessage(), [
            'data' => $this->data,
            'status' => $this->response->status(),
            'body' => Str::limit($this->response->body(), 500),
        ]);
    }

    /**
     * Render
     */
    public function render($request)
    {
        $status = $this->response->status();
        
        $detail = match($status) {
            404 => 'Recurso no encontrado',
            401, 403 => 'Error de autenticación con el servicio',
            default => 'Error de comunicación con el servicio'
        };

        return response()->json([
            'message' => 'Random API Error',
            'detail' => $detail
        ], $status);
    }
}
