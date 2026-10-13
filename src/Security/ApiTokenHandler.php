<?php

namespace App\Security;

use App\Repository\ApiTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/** Bearer token -> user. Tokens expire after ApiToken::IDLE_LIFETIME without use. */
final class ApiTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(private ApiTokenRepository $tokens, private EntityManagerInterface $em)
    {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $token = $this->tokens->findByPlainToken($accessToken);
        $now = new \DateTimeImmutable();
        if ($token === null || $token->isExpired($now)) {
            throw new BadCredentialsException('Invalid token.');
        }
        // One write per hour at most, not one per request.
        if ($token->getLastUsedAt() < $now->modify('-1 hour')) {
            $token->touch($now);
            $this->em->flush();
        }
        $user = $token->getUser();

        return new UserBadge($user->getUserIdentifier(), fn () => $user);
    }
}
