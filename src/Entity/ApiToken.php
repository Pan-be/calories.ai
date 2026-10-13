<?php

namespace App\Entity;

use App\Repository\ApiTokenRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A login session: the web app and (later) the iOS app send the token as
 * `Authorization: Bearer <token>`. Only its SHA-256 is stored.
 */
#[ORM\Entity(repositoryClass: ApiTokenRepository::class)]
#[ORM\Table(name: 'api_tokens')]
class ApiToken
{
    /** A token unused for this long stops working. */
    public const IDLE_LIFETIME = '+90 days';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 64, unique: true)]
    private string $hash;

    #[ORM\Column(length: 100)]
    private string $label;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $lastUsedAt;

    public function __construct(User $user, string $hash, string $label)
    {
        $this->user = $user;
        $this->hash = $hash;
        $this->label = mb_substr($label, 0, 100);
        $this->createdAt = $this->lastUsedAt = new \DateTimeImmutable();
    }

    public function getUser(): User { return $this->user; }
    public function getLastUsedAt(): \DateTimeImmutable { return $this->lastUsedAt; }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $this->lastUsedAt->modify(self::IDLE_LIFETIME) < $now;
    }

    public function touch(\DateTimeImmutable $now): void
    {
        $this->lastUsedAt = $now;
    }
}
