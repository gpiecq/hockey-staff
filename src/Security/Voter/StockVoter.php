<?php

declare(strict_types=1);

namespace App\Security\Voter;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Droits sur le stock : consultation Viewer+, mouvements Stock+, gestion des lieux Manager+.
 *
 * @extends Voter<string, mixed>
 */
final class StockVoter extends Voter
{
    public const VIEW = 'STOCK_VIEW';
    public const MOVE = 'STOCK_MOVE';
    public const LOCATION_MANAGE = 'LOCATION_MANAGE';

    private const ROLES = [
        self::VIEW => 'ROLE_VIEWER',
        self::MOVE => 'ROLE_STOCK',
        self::LOCATION_MANAGE => 'ROLE_MANAGER',
    ];

    public function __construct(private readonly Security $security)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return isset(self::ROLES[$attribute]);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        return $this->security->isGranted(self::ROLES[$attribute]);
    }
}
