<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MenuController extends AbstractController
{
    #[Route('/menu', name: 'app_menu', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('menu/index.html.twig');
    }
}
