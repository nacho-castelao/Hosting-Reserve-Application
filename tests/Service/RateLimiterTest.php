<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Exception\TooManyRequestsException;
use App\Service\RateLimiter;
use PHPUnit\Framework\TestCase;
use Tests\Fake\InMemoryLimitePeticionRepository;

final class RateLimiterTest extends TestCase
{
    private InMemoryLimitePeticionRepository $repository;
    private RateLimiter $limiter;

    protected function setUp(): void
    {
        $this->repository = new InMemoryLimitePeticionRepository();
        $this->limiter = new RateLimiter($this->repository);
    }

    private function consumir(int $veces, string $sujeto = '203.0.113.7'): void
    {
        for ($i = 0; $i < $veces; $i++) {
            $this->limiter->consumir(RateLimiter::LOGIN_EMAIL, $sujeto);
        }
    }

    public function testAllowsUpToTheLimit(): void
    {
        $this->consumir(RateLimiter::LIMITES[RateLimiter::LOGIN_EMAIL]['max']);

        $this->addToAssertionCount(1);
    }

    public function testOverTheLimitIs429WithRetryAfter(): void
    {
        $this->consumir(5);
        $this->repository->now += 300;

        try {
            $this->consumir(1);
            self::fail('Se esperaba una TooManyRequestsException.');
        } catch (TooManyRequestsException $e) {
            self::assertSame(429, $e->getStatusCode());
            self::assertSame(600, $e->getRetryAfter());
            self::assertSame(['Retry-After' => '600'], $e->getHeaders());
        }
    }

    public function testWindowResets(): void
    {
        $this->consumir(5);
        $this->repository->now += RateLimiter::LIMITES[RateLimiter::LOGIN_EMAIL]['ventana'];

        $this->consumir(5);

        $this->addToAssertionCount(1);
    }

    public function testSubjectsAndBucketsAreIndependent(): void
    {
        $this->consumir(5, 'a@example.com');
        $this->consumir(5, 'b@example.com');
        $this->limiter->consumir(RateLimiter::LOGIN_IP, 'a@example.com');

        $this->addToAssertionCount(1);
    }

    public function testClearResetsTheCounter(): void
    {
        $this->consumir(5);
        $this->limiter->limpiar(RateLimiter::LOGIN_EMAIL, '203.0.113.7');

        $this->consumir(5);

        $this->addToAssertionCount(1);
    }

    public function testKeysAreHashedSoRawSubjectsAreNotStored(): void
    {
        $this->consumir(1, '203.0.113.7');

        $clave = array_key_first($this->repository->rows);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $clave);
        self::assertStringNotContainsString('203.0.113.7', $clave);
    }
}
