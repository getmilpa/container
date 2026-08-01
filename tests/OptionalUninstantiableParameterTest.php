<?php

declare(strict_types=1);

namespace Milpa\Container\Tests;

use Milpa\Container\DIContainer;
use Milpa\Exceptions\ContainerResolutionException;
use PHPUnit\Framework\TestCase;

/**
 * Un parámetro con valor por defecto no puede tumbar la resolución de su clase.
 *
 * El contenedor intentaba construir CUALQUIER parámetro cuyo tipo pasara `class_exists()` — lo que
 * incluye `Closure`, `Generator` y toda clase interna reservada— y envolvía el intento en
 * `catch (\Exception)`. `new Closure()` lanza `\Error`, que ese catch no atrapa, así que la rama
 * «usa el default» escrita diez líneas más abajo nunca se alcanzaba.
 *
 * Y fallaba del peor modo posible: la clase se declaraba resoluble, la app arrancaba, y tronaba hasta
 * que alguien pedía ese servicio. Lo encontró un handler con una costura de pruebas —`?\Closure
 * $runner = null`— cuyo default estaba ahí precisamente para no necesitar nunca que el contenedor lo
 * construyera.
 */
final class OptionalUninstantiableParameterTest extends TestCase
{
    private DIContainer $contenedor;

    protected function setUp(): void
    {
        $this->contenedor = new DIContainer();
    }

    /** El caso que lo destapó: un `?\Closure` con default se queda con su default. */
    public function testAnOptionalClosureParameterFallsBackToItsDefault(): void
    {
        $servicio = $this->contenedor->get(ConCosturaDeCierre::class);

        self::assertInstanceOf(ConCosturaDeCierre::class, $servicio);
        self::assertNull($servicio->runner, 'el default estaba ahí para esto');
    }

    /**
     * Y no es sólo `Closure`.
     *
     * `Generator` reporta `isInstantiable() === true` y aun así lanza al construirse: está reservada
     * para uso interno. Por eso el predicado más estricto no bastaba y el catch tuvo que ensancharse
     * a `\Throwable` — dos arreglos para dos formas distintas del mismo «PHP no me deja».
     */
    public function testAnOptionalGeneratorParameterFallsBackToo(): void
    {
        $servicio = $this->contenedor->get(ConGeneradorOpcional::class);

        self::assertInstanceOf(ConGeneradorOpcional::class, $servicio);
        self::assertNull($servicio->gen);
    }

    /** Un parámetro nullable SIN default también tiene a dónde caer: a `null`. */
    public function testANullableParameterWithoutADefaultBecomesNull(): void
    {
        $servicio = $this->contenedor->get(ConCierreNullable::class);

        self::assertInstanceOf(ConCierreNullable::class, $servicio);
        self::assertNull($servicio->runner);
    }

    /**
     * Sin nada a donde caer, sigue siendo un fallo — y del mismo tipo que antes.
     *
     * Quien atrapaba `ContainerResolutionException` alrededor de `get()` la sigue atrapando: ensanchar
     * el catch de adentro no podía convertirse en un `\Error` escapándose por fuera.
     */
    public function testWithNothingToFallBackOnItStillFails(): void
    {
        try {
            $this->contenedor->get(ConCierreObligatorio::class);
            self::fail('un parámetro sin default ni null no se puede resolver');
        } catch (ContainerResolutionException $e) {
            self::assertStringContainsString('runner', $e->getMessage());
            self::assertStringContainsString(ConCierreObligatorio::class, $e->getMessage());
        }
    }

    /**
     * Y cuando SÍ hubo un intento que tronó, la causa viaja pegada.
     *
     * `Generator` pasa el predicado de instanciabilidad, así que el contenedor lo intenta de verdad y
     * recibe un `\Error`. Ese error es lo único que dice QUÉ pasó; el envoltorio dice cuál parámetro
     * quedó sin llenar. Son dos preguntas distintas y ninguna contesta la otra, así que la de adentro
     * viaja como `previous` en vez de perderse.
     */
    public function testAnAttemptThatBlewUpTravelsAsTheCause(): void
    {
        try {
            $this->contenedor->get(ConGeneradorObligatorio::class);
            self::fail('un Generator no se puede construir a mano');
        } catch (ContainerResolutionException $e) {
            self::assertInstanceOf(\Throwable::class, $e->getPrevious());
            self::assertStringContainsString('Generator', (string) $e->getPrevious()?->getMessage());
        }
    }

    /** Lo normal sigue igual: una dependencia de verdad se autoresuelve, no se cae al default. */
    public function testARealDependencyIsStillAutowiredAndNotDefaulted(): void
    {
        $servicio = $this->contenedor->get(ConDependenciaDeVerdad::class);

        self::assertInstanceOf(Colaborador::class, $servicio->colaborador);
    }

    /** Una clase con constructor privado tampoco se intenta: es otra forma de «PHP no me deja». */
    public function testAPrivateConstructorIsNotAttemptedEither(): void
    {
        $servicio = $this->contenedor->get(ConSingletonOpcional::class);

        self::assertInstanceOf(ConSingletonOpcional::class, $servicio);
        self::assertNull($servicio->singleton);
    }
}

final class Colaborador
{
}

final class ConCosturaDeCierre
{
    public function __construct(public readonly ?\Closure $runner = null)
    {
    }
}

final class ConGeneradorOpcional
{
    public function __construct(public readonly ?\Generator $gen = null)
    {
    }
}

final class ConCierreNullable
{
    public function __construct(public readonly ?\Closure $runner)
    {
    }
}

final class ConCierreObligatorio
{
    public function __construct(public readonly \Closure $runner)
    {
    }
}

final class ConGeneradorObligatorio
{
    public function __construct(public readonly \Generator $gen)
    {
    }
}

final class ConDependenciaDeVerdad
{
    public function __construct(public readonly Colaborador $colaborador)
    {
    }
}

final class SoloPorFabrica
{
    private function __construct()
    {
    }
}

final class ConSingletonOpcional
{
    public function __construct(public readonly ?SoloPorFabrica $singleton = null)
    {
    }
}
