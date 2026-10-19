<?php

declare(strict_types=1);

namespace Tests\Domain;

use App\Domain\Importe;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImporteTest extends TestCase
{
    #[DataProvider('casos')]
    public function testNightsTimesPriceIsExact(string $precio, int $noches, string $esperado): void
    {
        self::assertSame($esperado, Importe::porNoches($precio, $noches));
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function casos(): iterable
    {
        yield 'una noche' => ['120.00', 1, '120.00'];
        // Con floats, 0.1 * 3 = 0.30000000000000004.
        yield 'céntimos sin error de coma flotante' => ['0.10', 3, '0.30'];
        yield 'un decimal' => ['89.9', 3, '269.70'];
        yield 'sin decimales' => ['95', 7, '665.00'];
        yield 'precio alto' => ['99999.99', 90, '8999999.10'];
    }

    public function testZeroNightsIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Importe::porNoches('100.00', 0);
    }

    public function testTotalThatDoesNotFitInTheColumnIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Importe::porNoches('99999999.99', 2);
    }

    public function testCentsRoundTrip(): void
    {
        self::assertSame(123456, Importe::aCentimos('1234.56'));
        self::assertSame('0.05', Importe::desdeCentimos(5));
        self::assertSame('1234.50', Importe::desdeCentimos(Importe::aCentimos('1234.5')));
    }
}
