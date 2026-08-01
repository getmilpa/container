<?php

declare(strict_types=1);

namespace Milpa\Container\Tests;

use Milpa\Container\DIContainer;
use Milpa\Exceptions\ServiceRedefinitionException;
use PHPUnit\Framework\TestCase;

interface Sumidero
{
}

final class SumideroUno implements Sumidero
{
}

final class SumideroDos implements Sumidero
{
}

/**
 * El contenedor deja de pisar en silencio.
 *
 * Medido antes de elegir la política: en un arranque real hay 96 registros y 2 duplicados, y los dos
 * son la MISMA instancia registrada dos veces (`HostBoot` y `Kernel` cableando el mismo dispatcher y
 * el mismo registro de herramientas). Así que rechazar un servicio distinto no rompe nada vivo, y
 * rechazar una re-registración idéntica habría roto el arranque en dos lugares — por eso son casos
 * separados y no uno solo.
 *
 * Esto NO decide que la multiplicidad sea inválida (ADR-0037 dejó esa autoridad abierta a propósito).
 * Rechaza la única opción deshonesta bajo cualquier lectura: sustituir en silencio.
 */
final class ServiceRedefinitionTest extends TestCase
{
    public function testTheSameInstanceRegisteredTwiceIsANoOp(): void
    {
        $c = new DIContainer();
        $uno = new SumideroUno();

        $c->registerService(Sumidero::class, $uno);
        $c->registerService(Sumidero::class, $uno);

        self::assertSame($uno, $c->get(Sumidero::class));
    }

    public function testTheSameClassRegisteredTwiceIsANoOp(): void
    {
        $c = new DIContainer();

        $c->registerService(Sumidero::class, SumideroUno::class);
        $c->registerService(Sumidero::class, SumideroUno::class);

        self::assertInstanceOf(SumideroUno::class, $c->get(Sumidero::class));
    }

    public function testADifferentInstanceUnderATakenIdIsRefusedInsteadOfSwallowed(): void
    {
        $c = new DIContainer();
        $uno = new SumideroUno();
        $c->registerService(Sumidero::class, $uno);

        $this->expectException(ServiceRedefinitionException::class);
        $c->registerService(Sumidero::class, new SumideroDos());
    }

    public function testTheRefusalCarriesTheFourFactsTheOverwriteUsedToDestroy(): void
    {
        $c = new DIContainer();
        $c->registerService(Sumidero::class, new SumideroUno());

        try {
            $c->registerService(Sumidero::class, new SumideroDos());
            self::fail('debió rechazar');
        } catch (ServiceRedefinitionException $e) {
            $msg = $e->getMessage();
            self::assertStringContainsString(Sumidero::class, $msg, 'el id');
            self::assertStringContainsString(SumideroUno::class, $msg, 'quién estaba');
            self::assertStringContainsString(SumideroDos::class, $msg, 'quién llega');
            self::assertMatchesRegularExpression(
                '/from ServiceRedefinitionTest\.php:\d+/',
                $msg,
                'de dónde viene cada uno — sin esto el lector tiene que grepear el id, que es justo la '
                . 'búsqueda que el pisado silencioso volvía necesaria',
            );
        }
    }

    public function testTheStateDoesNotChangeWhenTheSecondRegistrationIsRefused(): void
    {
        // Fallar y ADEMÁS haber cambiado el estado sería lo peor de las dos opciones.
        $c = new DIContainer();
        $uno = new SumideroUno();
        $c->registerService(Sumidero::class, $uno);

        try {
            $c->registerService(Sumidero::class, new SumideroDos());
        } catch (ServiceRedefinitionException) {
            // esperado
        }

        self::assertSame($uno, $c->get(Sumidero::class));
    }

    public function testAnInstanceAndAClassNamingTheSameTypeAreStillTwoDifferentRegistrations(): void
    {
        // Deliberado: una instancia ya construida y una clase por resolver NO son el mismo hecho —
        // una trae estado y la otra no. Tratarlas como iguales volvería a esconder una sustitución.
        $c = new DIContainer();
        $c->registerService(Sumidero::class, new SumideroUno());

        $this->expectException(ServiceRedefinitionException::class);
        $c->registerService(Sumidero::class, SumideroUno::class);
    }
}
