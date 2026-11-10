<?php

namespace App\Entity;

use App\Repository\N8nEmailDeliveryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: N8nEmailDeliveryRepository::class)]
#[ORM\Index(fields: ['nextAttemptAt'])]
class N8nEmailDelivery
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ReceivedEmail $email;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $nextAttemptAt;

    #[ORM\Column]
    private int $failedAttempts = 0;

    public function __construct(ReceivedEmail $email)
    {
        $this->email = $email;
        $this->nextAttemptAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ReceivedEmail
    {
        return $this->email;
    }

    public function getFailedAttempts(): int
    {
        return $this->failedAttempts;
    }
}
