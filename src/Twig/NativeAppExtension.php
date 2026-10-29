<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Attribute\AsTwigFunction;

final class NativeAppExtension
{
    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    /** Vrai quand la page est affichée dans l'app native (Hotwire Native) : la navigation web est alors masquée. */
    #[AsTwigFunction('is_native_app')]
    public function isNativeApp(): bool
    {
        $userAgent = $this->requestStack->getMainRequest()?->headers->get('User-Agent');

        return null !== $userAgent && str_contains($userAgent, 'Hotwire Native');
    }
}
