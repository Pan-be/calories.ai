<?php

namespace App\Security;

use App\Entity\ApiToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final class TokenIssuer
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /** A new random token for $user; the plain value is returned once and never stored. */
    public function issue(User $user, string $label): string
    {
        $plain = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->em->persist(new ApiToken($user, hash('sha256', $plain), $label));
        $this->em->flush();

        return $plain;
    }
}
