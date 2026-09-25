<?php

namespace App\Domain;

/**
 * Familias comerciales de cotización. Sustituye la constante duplicada
 * `CatalogController::FAMILIES`: se usa en catálogo, reglas comerciales,
 * históricos y cotizaciones.
 */
enum QuoteFamily: string
{
    case Cctv = 'cctv';
    case DataPower = 'data_power';
    case Equipment = 'equipment';
    case Software = 'software';
    case Ups = 'ups';
    case Security = 'security';
    case Services = 'services';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $family): string => $family->value, self::cases());
    }
}
