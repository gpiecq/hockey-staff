<?php

declare(strict_types=1);

namespace App\Controller\Stock;

use App\Catalogue\CodeResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Page de scan : un code (douchette, caméra ou saisie) mène à la fiche de la variante.
 */
#[IsGranted('STOCK_VIEW')]
final class ScanController extends AbstractController
{
    #[Route('/scan', name: 'scan_index', methods: ['GET'])]
    public function index(Request $request, CodeResolver $resolver): Response
    {
        $code = trim($request->query->getString('code'));
        $unknown = false;

        if ('' !== $code) {
            $variant = $resolver->resolve($code);
            if (null !== $variant && null !== $variant->getProduct()) {
                return $this->redirectToRoute('stock_variant_show', ['id' => $variant->getId()]);
            }
            $unknown = true;
        }

        return $this->render('scan/index.html.twig', ['code' => $code, 'unknown' => $unknown]);
    }
}
