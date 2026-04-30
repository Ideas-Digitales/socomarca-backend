<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RandomApiServiceErrorException extends Exception
{
    protected array $data;
    protected ?\Illuminate\Http\Client\Response $response;

    public function __construct(
        string $message,
        array $data,
        ?\Illuminate\Http\Client\Response $response = null
    ) {
        $status = $response?->status() ?? 500;
        parent::__construct($message, $status);
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
            'status' => $this->response?->status() ?? 500,
            'body' => Str::limit($this->response?->body() ?? "", 500),
        ]);
    }

    /**
     * Render
     */
    public function render($request)
    {
        $status = $this->response?->status() ?? 500;

        if (!in_array($status, [401, 403, 404])) {
            $status = 500;
        }

        $detail = match($status) {
            404 => 'Recurso no encontrado en Random API',
            401, 403 => 'Error de autenticación con el servicio de Random API',
            default => 'Error de comunicación con el servicio de Random API'
        };

        return response()->json([
            'message' => $this->getMessage(), // Using the specific message provided
            'detail' => $detail
        ], $status);
    }
}
