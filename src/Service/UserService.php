<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

class UserService
{
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
    ) {
    }

    /**
     * @throws UniqueConstraintViolationException
     */
    public function storeUser(string $login): void
    {
        $user = new User();
        $user->setLogin($login);
        $user->setPassword(base64_encode(random_bytes(32)));
        $this->managerRegistry->getManager()->persist($user);
        $this->managerRegistry->getManager()->flush();
    }

    public function refreshManager(): void
    {
        $this->managerRegistry->resetManager();
    }
}
