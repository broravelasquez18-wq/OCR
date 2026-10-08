<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Punto unico donde vive la normalizacion de numeros de documento del lado PHP.
 * Se usa al importar el .xls y cuando el operador pega/edita un numero a mano
 * (quitar "CC -", puntos, espacios, etc.).
 */
final class NumeroExtractor
{
    /**
     * Deja solo digitos. "CC - 1.110.487.315" -> "1110487315".
     */
    public static function normalizar(?string $raw): string
    {
        if ($raw === null) {
            return '';
        }
        return preg_replace('/\D+/', '', $raw) ?? '';
    }

    /**
     * Separa tipo de documento y numero desde una celda tipo "CC - 1110487315".
     * Devuelve ['tipo' => 'CC', 'numero' => '1110487315'].
     * Si no hay tipo reconocible, tipo por defecto 'CC'.
     *
     * @return array{tipo:string, numero:string}
     */
    public static function separarTipoYNumero(?string $raw): array
    {
        $raw = trim((string) $raw);
        $tipo = 'CC';

        // Buscar un prefijo alfabetico (CC, TI, CE, PEP, PPT, NIT...).
        if (preg_match('/^\s*([A-Za-z]{2,4})\b/', $raw, $m)) {
            $tipo = strtoupper($m[1]);
        }

        return [
            'tipo'   => $tipo,
            'numero' => self::normalizar($raw),
        ];
    }
}
