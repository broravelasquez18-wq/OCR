<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;

final class Edad
{
    public static function calcular(?string $fecha, ?DateTimeImmutable $hoy = null): ?int
    {
        if ($fecha === null || $fecha === '') {
            return null;
        }
        $zona = new DateTimeZone('America/Bogota');
        $nacimiento = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha, $zona);
        $hoy = ($hoy ?? new DateTimeImmutable('today', $zona))->setTime(0, 0);
        if ($nacimiento === false || $nacimiento->format('Y-m-d') !== $fecha || $nacimiento > $hoy) {
            return null;
        }
        return $nacimiento->diff($hoy)->y;
    }
}
