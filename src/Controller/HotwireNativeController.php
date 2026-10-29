<?php

declare(strict_types=1);

namespace App\Controller;

use App\HotwireNative\PathConfiguration;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/hotwire-native/v1')]
final class HotwireNativeController extends AbstractController
{
    public function __construct(private readonly PathConfiguration $pathConfiguration)
    {
    }

    #[Route('/ios-path-configuration.json', name: 'hotwire_native_path_configuration_ios', methods: ['GET'])]
    public function ios(): JsonResponse
    {
        return $this->cached($this->pathConfiguration->ios());
    }

    #[Route('/android-path-configuration.json', name: 'hotwire_native_path_configuration_android', methods: ['GET'])]
    public function android(): JsonResponse
    {
        return $this->cached($this->pathConfiguration->android());
    }

    /**
     * @param array{settings: \stdClass, rules: list<array{patterns: list<string>, properties: array<string, mixed>}>} $configuration
     */
    private function cached(array $configuration): JsonResponse
    {
        $response = new JsonResponse($configuration);
        $response->headers->set('Cache-Control', 'public, max-age=300');

        return $response;
    }
}
