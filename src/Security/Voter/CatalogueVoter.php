<?php

declare(strict_types=1);

namespace App\Security\Voter;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Droits sur le catalogue (fournisseurs, marques, catégories…) : lecture Viewer+, écriture Manager+.
 *
 * @extends Voter<string, mixed>
 */
final class CatalogueVoter extends Voter
{
    public const VIEW = 'CATALOGUE_VIEW';
    public const EDIT = 'CATALOGUE_EDIT';

    public function __construct(private readonly Security $security)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::EDIT], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        return $this->security->isGranted(self::VIEW === $attribute ? 'ROLE_VIEWER' : 'ROLE_MANAGER');
    }
}
