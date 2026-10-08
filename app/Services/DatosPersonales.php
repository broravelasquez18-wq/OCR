<?php

declare(strict_types=1);

namespace App\Services;

final class DatosPersonales
{
    public static function efectivos(array $fila): array
    {
        $fecha = (int) ($fila['fecha_manual'] ?? 0) === 1
            ? ($fila['fecha_confirmada'] ?? null)
            : ($fila['fecha_confirmada'] ?? $fila['fecha_nacimiento'] ?? null);
        return [
            'numero' => $fila['numero_confirmado'] ?? $fila['numero_leido'] ?? '',
            'nombres' => $fila['nombres_manual'] ?? $fila['nombres_ocr'] ?? '',
            'apellidos' => $fila['apellidos_manual'] ?? $fila['apellidos_ocr'] ?? '',
            'fecha' => $fecha,
            'edad' => Edad::calcular($fecha),
        ];
    }

    public static function faltantes(array $fila): array
    {
        $datos = self::efectivos($fila);
        $faltantes = [];
        foreach (['numero' => 'Identificación', 'nombres' => 'Nombres', 'apellidos' => 'Apellidos'] as $campo => $etiqueta) {
            if (trim((string) $datos[$campo]) === '') {
                $faltantes[] = $etiqueta;
            }
        }
        if ($datos['edad'] === null) {
            $faltantes[] = 'Fecha de nacimiento (edad sin calcular)';
        }
        return $faltantes;
    }
}
