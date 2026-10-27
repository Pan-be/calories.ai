<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Makes an account while registration is closed (KALORIE_REGISTRATION=first) and prints a
 * random password to hand over once; it can be changed in the app's settings.
 */
#[AsCommand('app:user:create', 'Create an account with a random password')]
final class CreateUserCommand
{
    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Argument('E-mail of the new account')] string $email): int
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error('Podaj poprawny adres e-mail.');
            return Command::INVALID;
        }
        if ($this->users->findByEmail($email) !== null) {
            $io->error('Konto z tym adresem już istnieje.');
            return Command::FAILURE;
        }

        $password = bin2hex(random_bytes(12));
        $user = new User($email);
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $this->em->persist($user);
        $this->em->flush();

        $io->success("Konto: $email");
        $io->writeln("Hasło: $password");

        return Command::SUCCESS;
    }
}
