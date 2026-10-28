<?php

declare(strict_types=1);

namespace App\Security\Voter;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Droits sur les joueurs : consultation Viewer+, gestion Manager+.
 *
 * @extends Voter<string, mixed>
 */
final class PlayerVoter extends Voter
{
    public const VIEW = 'PLAYER_VIEW';
    public const MANAGE = 'PLAYER_MANAGE';

    private const ROLES = [
        self::VIEW => 'ROLE_VIEWER',
        self::MANAGE => 'ROLE_MANAGER',
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
