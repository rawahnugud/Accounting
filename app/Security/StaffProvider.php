<?php
declare(strict_types=1);

namespace App\Security;

use PDO;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

final class StaffProvider implements UserProviderInterface
{
    public function __construct(private PDO $db) {}

    public function loadUserByIdentifier(string $identifier): InMemoryUser
    {
        $query = $this->db->prepare('SELECT email, password FROM users WHERE email = ?');
        $query->execute([$identifier]);
        $row = $query->fetch();
        if (!$row) {
            throw new UserNotFoundException();
        }
        return new InMemoryUser($row['email'], $row['password'], ['ROLE_STAFF']);
    }

    public function refreshUser(UserInterface $user): InMemoryUser
    {
        if (!$user instanceof InMemoryUser) {
            throw new UnsupportedUserException();
        }
        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return $class === InMemoryUser::class;
    }
}
