<?php

declare(strict_types=1);

namespace Tests\Validation;

use App\Exception\ValidationException;
use App\Validation\ReservaValidator;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReservaValidatorTest extends TestCase
{
    private ReservaValidator $validator;

    protected function setUp(): void
    {
        // "Hoy" fijo para que los tests no dependan de la fecha real.
        $this->validator = new ReservaValidator(new DateTimeImmutable('2026-10-15'));
    }

    /** @return array<string, mixed> */
    private static function validPayload(): array
    {
        return [
            'nombre_huesped' => '  María García  ',
            'email_huesped' => 'maria@example.com',
            'nombre_alojamiento' => 'Hotel Gran Vía',
            'fecha_entrada' => '2026-10-15',
            'fecha_salida' => '2026-10-18',
            'importe' => 450.5,
            'notas' => 'Llegada tarde',
        ];
    }

    /** @return array<string, string> */
    private function errorsFor(array $payload): array
    {
        try {
            $this->validator->validateCreate($payload);
        } catch (ValidationException $e) {
            return $e->getFields();
        }
        self::fail('Se esperaba una ValidationException.');
    }

    public function testValidPayloadIsNormalized(): void
    {
        $reserva = $this->validator->validateCreate(self::validPayload());

        self::assertSame('María García', $reserva->nombreHuesped);
        self::assertSame('2026-10-15', $reserva->fechaEntrada->format('Y-m-d'));
        self::assertSame('450.50', $reserva->importe);
        self::assertSame('Llegada tarde', $reserva->notas);
    }

    public function testAllMissingFieldsAreReportedAtOnce(): void
    {
        $errors = $this->errorsFor([]);

        self::assertEqualsCanonicalizing(
            ['nombre_huesped', 'email_huesped', 'nombre_alojamiento', 'fecha_entrada', 'fecha_salida', 'importe'],
            array_keys($errors),
        );
    }

    public function testBlankStringsCountAsMissing(): void
    {
        $errors = $this->errorsFor(['nombre_huesped' => '   '] + self::validPayload());

        self::assertSame(['nombre_huesped' => 'Este campo es obligatorio.'], $errors);
    }

    public function testInvalidEmailIsRejected(): void
    {
        $errors = $this->errorsFor(['email_huesped' => 'maria@'] + self::validPayload());

        self::assertArrayHasKey('email_huesped', $errors);
        self::assertCount(1, $errors);
    }

    #[DataProvider('salidaNoPosterior')]
    public function testSalidaMustBeAfterEntrada(string $salida): void
    {
        $errors = $this->errorsFor(['fecha_salida' => $salida] + self::validPayload());

        self::assertSame(['fecha_salida' => 'La fecha de salida debe ser posterior a la de entrada.'], $errors);
    }

    /** @return iterable<string, array{string}> */
    public static function salidaNoPosterior(): iterable
    {
        yield 'mismo día' => ['2026-10-15'];
        yield 'anterior' => ['2026-10-01'];
    }

    #[DataProvider('invalidDates')]
    public function testImpossibleOrMalformedDatesAreRejected(string $date): void
    {
        $errors = $this->errorsFor(['fecha_entrada' => $date] + self::validPayload());

        self::assertSame('Debe ser una fecha válida con formato AAAA-MM-DD.', $errors['fecha_entrada']);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidDates(): iterable
    {
        yield '30 de febrero' => ['2027-02-30'];
        yield 'mes 13' => ['2027-13-01'];
        yield 'sin ceros' => ['2027-1-5'];
        yield 'formato europeo' => ['15/10/2027'];
        yield 'con hora' => ['2027-01-05 10:00'];
    }

    public function testEntradaCannotBeInThePast(): void
    {
        $errors = $this->errorsFor(['fecha_entrada' => '2026-10-14'] + self::validPayload());

        self::assertSame(['fecha_entrada' => 'La fecha de entrada no puede ser anterior a hoy.'], $errors);
    }

    #[DataProvider('invalidImportes')]
    public function testInvalidImporteIsRejected(mixed $importe): void
    {
        $errors = $this->errorsFor(['importe' => $importe] + self::validPayload());

        self::assertArrayHasKey('importe', $errors);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidImportes(): iterable
    {
        yield 'negativo' => [-10];
        yield 'tres decimales' => ['10.555'];
        yield 'texto' => ['diez'];
        yield 'booleano' => [true];
        yield 'demasiado grande' => ['100000000'];
    }

    public function testServerControlledAndUnknownFieldsAreRejected(): void
    {
        $errors = $this->errorsFor(['id' => 99, 'estado' => 'confirmada', 'admin' => true] + self::validPayload());

        self::assertEqualsCanonicalizing(['id', 'estado', 'admin'], array_keys($errors));
    }

    public function testTooLongTextIsRejected(): void
    {
        $errors = $this->errorsFor(['nombre_huesped' => str_repeat('ñ', 151)] + self::validPayload());

        self::assertSame('No puede superar los 150 caracteres.', $errors['nombre_huesped']);
    }

    public function testInvalidFiltersThrow400(): void
    {
        try {
            $this->validator->validateFiltros(['estado' => 'borrada', 'desde' => '2026-02-30', 'limit' => '0']);
            self::fail('Se esperaba una ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(400, $e->getStatusCode());
            self::assertEqualsCanonicalizing(['estado', 'desde', 'limit'], array_keys($e->getFields()));
        }
    }

    public function testLimitIsCappedAt100(): void
    {
        self::assertSame(100, $this->validator->validateFiltros(['limit' => '5000'])->limit);
    }
}
