<?php

declare(strict_types=1);

namespace App\Core;

use App\Domain\UsuarioActual;
use Closure;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class View
{
    private Environment $twig;

    /**
     * @param Closure(): ?UsuarioActual $usuario Usuario de la petición (perezoso: solo se
     *        consulta si la plantilla lo necesita).
     */
    public function __construct(string $templatesDir, ?string $cacheDir, bool $debug, string $basePath, Closure $usuario)
    {
        $this->twig = new Environment(new FilesystemLoader($templatesDir), [
            'cache' => $debug || $cacheDir === null ? false : $cacheDir,
            'debug' => $debug,
            'autoescape' => 'html',          // escapado automático de toda variable
            'strict_variables' => $debug,    // en local, una variable inexistente es un error
        ]);

        $this->twig->addGlobal('base_url', $basePath);
        $this->twig->addFunction(new TwigFunction('url', static fn (string $path = '/'): string => $basePath . $path));
        $this->twig->addFunction(new TwigFunction(
            'asset',
            static fn (string $path): string => $basePath . '/assets/' . ltrim($path, '/'),
        ));
        // Perezoso: solo se abre sesión cuando una plantilla necesita el token.
        $this->twig->addFunction(new TwigFunction('csrf_token', static fn (): string => Csrf::token()));
        $this->twig->addFunction(new TwigFunction(
            'usuario_actual',
            static fn (): ?array => $usuario()?->toArray(),
        ));
    }

    /** @param array<string, mixed> $context */
    public function render(string $template, array $context = [], int $status = 200): Response
    {
        return new Response($this->twig->render($template, $context), $status);
    }
}
