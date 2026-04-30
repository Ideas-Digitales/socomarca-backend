<?php

namespace App\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class RandomMockProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        if (config('random.mock.documents.enabled')) {
            $baseUrl = config('random.url');

            if (config('random.mock.documents.response.bad')) {
                Http::fake([
                    "{$baseUrl}/web32/documento" => Http::response([
                        "message" => "7BBA50CB-186C-40E7-80DA-D918F4A3E993| No es posible determinar una modalidad para la combinación=> empresa=01 tido=FCV, modalidad=QSDOO",
                        "errorId" => "YJOBmmSN",
                        "logUrl" => "http=>//localhost=>3111/xlogger?reqId=eK1hV-hW"
                    ], 200),
                ]);
            } else {
                Http::fake([
                    "{$baseUrl}/web32/documento" => Http::response([
                        "numero" => "0000000001",
                        "tido" => "FAKEDOC",
                        "idmaeedo" => 657,
                        "uidxmaeedo" => "2CEE468D-35B7-4CBA-A243-E2D20C13C8D7",
                        "vabrdo" => 7140,
                        "moneda" => "CLP",
                        "estado" => [
                            "codigo" => "1",
                            "mensaje" => "Grabación exitosa"
                        ]
                    ], 200),
                ]);
            }
        }

        if (config('random.mock.credit.branch')) {
            $baseUrl = config('random.url');
            Log::debug('Random URL (provider): ' . $baseUrl);
            Http::fake([
                "{$baseUrl}/gestion/credito/resumen/*" => function ($request) {
                    $segments = explode('/', parse_url($request->url(), PHP_URL_PATH));

                    $suen = end($segments);
                    $koen = prev($segments);

                    return Http::response([
                        "KOEN"   => $koen,
                        "SUEN"   => $suen,
                        "CRSD"   => 50092358399999.99,
                        "CRSDVU" => 5915690,
                        "CRSDVV" => 1046672,
                        "CRSDCU" => 0,
                        "CRSDCV" => 0
                    ], 200);
                },
            ]);
        }
    }
}
