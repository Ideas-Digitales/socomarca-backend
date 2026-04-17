<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Support\Facades\Log;

class RandomApiServiceErrorException extends Exception
{
    protected $context;

    public function __construct($message, $code = 500, $context = [])
    {
        parent::__construct($message, $code);
        $this->context = $context;
    }

    /**
     * Report
     */
    public function report()
    {
        Log::error('Random API' . $this->getMessage(), [
            'status_code' => $this->getCode(),
            'payload'     => $this->context['payload'] ?? null,
            'response'    => $this->context['response_fragment'] ?? null,
        ]);
    }

    /**
     * Render
     */
    public function render($request)
    {
        if ($this->getCode() === 404) {
            return response()->json([
                'message' => 'Random API Error',
                'detail' => 'Recurso no encontrado'
            ], 404);
        }
        
        return response()->json([
            'message' => 'Random API Error',
            'detail' => 'Error de comunicación con Random API'
        ], 500);
    }
}
