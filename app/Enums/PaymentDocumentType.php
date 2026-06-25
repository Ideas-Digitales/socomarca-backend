<?php

namespace App\Enums;

class PaymentDocumentType
{
    const INVOICE = 'invoice';
    const RECEIPT = 'receipt';

    /**
     * Get all payment document types.
     * @return array
     */
    public static function values(): array
    {
        return [
            self::INVOICE,
            self::RECEIPT,
        ];
    }

    /**
     * Get all payment document types labels.
     * @return array
     */
    public static function labels(): array
    {
        return [
            self::INVOICE => 'Factura',
            self::RECEIPT => 'Boleta',
        ];
    }

    /**
     * Get the label of a document type
     * @param string $type
     *
     * @return string
     */
    public static function getLabel(string $type): string
    {
        $labels = self::labels();

        if (!isset($labels[$type])) {
            throw new \Exception('Invalid payment document type');
        }

        return $labels[$type];
    }

    /**
     * Get a random sale status value.
     * @return string
     */
    public static function getRandomValue(): string
    {
        $values = self::values();
        return $values[array_rand($values)];
    }
}
