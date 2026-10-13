<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\ApiError;
use App\Http\Input;
use App\Nutrition\Presenter;
use App\Repository\ApiTokenRepository;
use App\Repository\UserRepository;
use App\Security\TokenIssuer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/auth')]
final class AuthController extends AbstractController
{
    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
        private TokenIssuer $tokens,
        private Presenter $presenter,
    ) {
    }

    #[Route('/register', methods: ['POST'])]
    public function register(Request $request, #[Autowire(env: 'KALORIE_REGISTRATION')] string $registration): JsonResponse
    {
        $allowed = match ($registration) {
            'open' => true,
            'first' => $this->users->count([]) === 0,
            default => false,
        };
        if (!$allowed) {
            throw new ApiError(403, 'registration_closed', 'Rejestracja jest zamknięta. Poproś o konto administratora.');
        }

        $in = Input::json($request);
        $email = mb_strtolower($in->string('email', 180));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ApiError::invalid('Podaj poprawny adres e-mail.');
        }
        $password = $in->string('password', 200);
        if (mb_strlen($password) < 8) {
            throw ApiError::invalid('Hasło musi mieć co najmniej 8 znaków.');
        }
        if ($this->users->findByEmail($email) !== null) {
            throw new ApiError(409, 'email_taken', 'Konto z tym adresem już istnieje.');
        }

        $user = new User($email);
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $timezone = $in->string('timezone', 64, false);
        if ($timezone !== null && in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            $user->setTimezone($timezone);
        }
        $this->em->persist($user);
        $this->em->flush();

        return $this->json([
            'token' => $this->tokens->issue($user, $this->label($request)),
            'user' => $this->presenter->user($user),
        ], 201);
    }

    #[Route('/login', methods: ['POST'])]
    public function login(Request $request, RateLimiterFactoryInterface $loginLimiter): JsonResponse
    {
        $in = Input::json($request);
        $email = mb_strtolower($in->string('email', 180));
        $password = $in->string('password', 200);

        $limit = $loginLimiter->create($email . '|' . $request->getClientIp())->consume();
        if (!$limit->isAccepted()) {
            throw new ApiError(429, 'too_many_attempts', 'Za dużo prób logowania. Spróbuj za kilkanaście minut.',
                ['Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time())]);
        }

        $user = $this->users->findByEmail($email);
        if ($user === null || !$this->hasher->isPasswordValid($user, $password)) {
            throw new ApiError(401, 'bad_credentials', 'Nieprawidłowy e-mail lub hasło.');
        }
        if ($this->hasher->needsRehash($user)) {
            $user->setPassword($this->hasher->hashPassword($user, $password));
            $this->em->flush();
        }

        return $this->json(['token' => $this->tokens->issue($user, $this->label($request)), 'user' => $this->presenter->user($user)]);
    }

    #[Route('/logout', methods: ['POST'])]
    public function logout(Request $request, ApiTokenRepository $tokens): JsonResponse
    {
        $plain = substr((string) $request->headers->get('Authorization'), 7);
        if (($token = $tokens->findByPlainToken($plain)) !== null) {
            $this->em->remove($token);
            $this->em->flush();
        }
        return $this->json(['ok' => true]);
    }

    private function label(Request $request): string
    {
        return (string) ($request->headers->get('X-Client') ?: $request->headers->get('User-Agent') ?: 'unknown');
    }
}
