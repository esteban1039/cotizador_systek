<?php

namespace App\Domain\Quotes;

/**
 * Reglas de familia, unidad y limpieza de descripción portadas de `generar_importacion.py`
 * (raíz del repositorio) para la carga inicial desde Drive. Puro y sin precios.
 */
final class DriveLineClassifier
{
    private const MAX_DESCRIPTION = 255;

    /** @var list<array{0: string, 1: string}> */
    private const SECTION_FAMILY = [
        ['CCTV', 'cctv'], ['ALARMA', 'security'], ['SONIDO', 'equipment'], ['HARDWARE', 'equipment'],
        ['EQUIPOS DE COMPUTO', 'equipment'], ['UPS', 'ups'], ['SOFTWARE', 'software'], ['SOPORTE TECNICO', 'services'],
        ['SERVICIOS', 'services'], ['SERVICIO DE INGENIERIA', 'services'], ['BACKUP NUBE', 'services'],
        ['INFRAESTRUCTURA CLOUD', 'services'], ['ILUMINACION', 'data_power'], ['INFRAESTRUCTURA', 'data_power'],
        ['DATOS', 'data_power'], ['RED', 'data_power'], ['CENTRO DE DATOS', 'data_power'], ['GESTION DE RED', 'data_power'],
        ['POTENCIA', 'data_power'], ['ADICIONAL CON EMT', 'cctv'],
    ];

    private const SOFTWARE_STARTS = ['software', 'licencia', 'licenciamiento', 'renovación', 'implementación de sistema', 'paquete office'];

    private const SERVICE_STARTS = ['instalación', 'servicio', 'mantenimiento', 'mano de obra', 'desmonte', 'revisión y servicio',
        'cambio de tramo', 'formateo', 'implementación', 'póliza', 'hora', 'aumento de memoria', 'actualización', 'ssd de'];

    private const SOUND_WORDS = ['parlante', 'amplificador', 'cabina', 'mixer', 'planta de sonido', 'cable encauchedato sonido',
        'cable blindado para sonido', 'cable de sonido'];

    public static function familyOf(string $section, string $description): string
    {
        $d = mb_strtolower($description);
        $s = mb_strtoupper($section);
        if (self::startsWith($d, self::SOFTWARE_STARTS)) {
            return 'software';
        }
        if (self::startsWith($d, ['ups', 'baterías']) || (str_starts_with($d, 'revisión y mantenimiento físico') && str_contains(mb_substr($d, 0, 80), 'ups'))) {
            return 'ups';
        }
        if (str_contains($d, 'mensual') && str_starts_with($s, 'HARDWARE')) {
            return 'services';
        }
        if ((str_starts_with($s, 'HARDWARE') || str_starts_with($s, 'INFRAESTRUCTURA'))
            && self::startsWith($d, ['mantenimiento', 'aumento de memoria', 'formateo', 'actualización'])) {
            return 'services';
        }
        if (! str_starts_with($d, 'instalación')) {
            foreach (self::SOUND_WORDS as $word) {
                if (str_contains($d, $word)) {
                    return 'equipment';
                }
            }
        }
        foreach (self::SECTION_FAMILY as [$prefix, $family]) {
            if (str_starts_with($s, $prefix)) {
                return $family;
            }
        }

        return 'equipment';
    }

    public static function unitOf(string $description, string $family): string
    {
        $d = mb_strtolower($description);
        if (str_starts_with($d, 'hora')) {
            return 'hora';
        }
        if (str_starts_with($d, 'póliza') || str_contains($d, 'mensual')) {
            return 'mes';
        }
        if ($family === 'software') {
            return str_starts_with($d, 'implementación') ? 'servicio' : 'licencia';
        }
        if (self::startsWith($d, self::SERVICE_STARTS)) {
            return 'servicio';
        }

        return 'unidad';
    }

    public static function cleanDescription(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $text = (string) preg_replace('/^(Opción|Opcion)\s*\d+\s*:\s*/u', '', $text);
        if ($text !== '') {
            $text = mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
        }
        if (mb_strlen($text) > self::MAX_DESCRIPTION) {
            $cut = mb_substr($text, 0, self::MAX_DESCRIPTION - 1);
            $space = mb_strrpos($cut, ' ');
            $text = rtrim($space === false ? $cut : mb_substr($cut, 0, $space), ' ,.;:').'…';
        }

        return $text;
    }

    /**
     * @param  list<string>  $prefixes
     */
    private static function startsWith(string $text, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($text, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
