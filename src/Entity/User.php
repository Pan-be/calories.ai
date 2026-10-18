<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * An account with its body profile and daily goals. Goals are computed from the profile
 * (see GoalCalculator) until the user sets them by hand ($goalsManual).
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const SEXES = ['male', 'female'];
    public const ACTIVITY = ['sedentary', 'light', 'moderate', 'active', 'very_active'];
    public const GOALS = ['lose', 'maintain', 'recomp', 'gain'];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    #[ORM\Column]
    private string $password = '';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(length: 64)]
    private string $timezone = 'Europe/Warsaw';

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $sex = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $birthDate = null;

    #[ORM\Column(nullable: true)]
    private ?float $heightCm = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $activity = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $goal = null;

    #[ORM\Column(nullable: true)]
    private ?float $targetWeightKg = null;

    #[ORM\Column]
    private int $goalKcal = 2000;

    #[ORM\Column]
    private int $goalProteinG = 120;

    #[ORM\Column]
    private int $goalCarbsG = 220;

    #[ORM\Column]
    private int $goalFatG = 65;

    #[ORM\Column]
    private bool $goalsManual = false;

    public function __construct(string $email)
    {
        $this->email = $email;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getEmail(): string { return $this->email; }
    public function getUserIdentifier(): string { return $this->email; }
    public function getRoles(): array { return ['ROLE_USER']; }
    public function getPassword(): string { return $this->password; }
    public function setPassword(string $hash): void { $this->password = $hash; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getTimezone(): string { return $this->timezone; }
    public function setTimezone(string $timezone): void { $this->timezone = $timezone; }
    public function zone(): \DateTimeZone { return new \DateTimeZone($this->timezone); }

    public function getSex(): ?string { return $this->sex; }
    public function setSex(?string $sex): void { $this->sex = $sex; }
    public function getBirthDate(): ?\DateTimeImmutable { return $this->birthDate; }
    public function setBirthDate(?\DateTimeImmutable $birthDate): void { $this->birthDate = $birthDate; }
    public function getHeightCm(): ?float { return $this->heightCm; }
    public function setHeightCm(?float $heightCm): void { $this->heightCm = $heightCm; }
    public function getActivity(): ?string { return $this->activity; }
    public function setActivity(?string $activity): void { $this->activity = $activity; }
    public function getGoal(): ?string { return $this->goal; }
    public function setGoal(?string $goal): void { $this->goal = $goal; }
    public function getTargetWeightKg(): ?float { return $this->targetWeightKg; }
    public function setTargetWeightKg(?float $kg): void { $this->targetWeightKg = $kg; }

    /** @return array{kcal:int, protein_g:int, carbs_g:int, fat_g:int} */
    public function getGoals(): array
    {
        return ['kcal' => $this->goalKcal, 'protein_g' => $this->goalProteinG, 'carbs_g' => $this->goalCarbsG, 'fat_g' => $this->goalFatG];
    }

    /** @param array{kcal:int, protein_g:int, carbs_g:int, fat_g:int} $goals */
    public function setGoals(array $goals, bool $manual): void
    {
        $this->goalKcal = $goals['kcal'];
        $this->goalProteinG = $goals['protein_g'];
        $this->goalCarbsG = $goals['carbs_g'];
        $this->goalFatG = $goals['fat_g'];
        $this->goalsManual = $manual;
    }

    public function areGoalsManual(): bool { return $this->goalsManual; }

    public function hasProfile(): bool
    {
        return $this->sex !== null && $this->birthDate !== null && $this->heightCm !== null
            && $this->activity !== null && $this->goal !== null;
    }

    public function eraseCredentials(): void
    {
    }
}
