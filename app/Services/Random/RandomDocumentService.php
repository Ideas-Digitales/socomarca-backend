<?php

namespace App\Services\Random;

use App\Models\Order;
use App\Services\RandomApiService;

class RandomDocumentService
{
    public function __construct(private RandomApiService $api)
    {
    }

    /**
     * Crea un documento en Random API y lo guarda localmente asociándolo a una orden
     * @see https://documenter.getpostman.com/view/467703/Tz5v3bJp#b383c619-3553-4895-a2f7-ea02f9f7218c
     * 
     * @param array $payload
     * @param Order $order
     * 
     * @return array Random Document Response
     */
    public function createDocument(array $payload, Order $order): array
    {
        // $payload = [
        //     'datos' => [
        //         'empresa' => config('random.business_code'),
        //         'codigoEntidad' => $user->user_code,
        //         'tido' => 'NVV',
        //         'modalidad' => config('random.modality'),
        //         'lineas' => $lines
        //     ]
        // ];

        $responseObject = $this->api->createDocument($payload);
        $response = $responseObject->json();
        $idmaeedo = $response['idmaeedo'] ?? null;

        if ($idmaeedo) {
            $randomDocument = \App\Models\RandomDocument::firstOrCreate(
                ['idmaeedo' => $idmaeedo],
                [
                    'type' => 'NVV',
                    'document' => $response
                ]
            );

            $order->randomDocuments()->attach($randomDocument->idmaeedo);
        }

        return $response;
    }
}
