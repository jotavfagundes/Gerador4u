<?php

namespace App\Service\Handler\ReceivedEmail;

use App\DTO\Request\CreateEmailRequestDto;
use App\Entity\N8nEmailDelivery;
use App\Entity\ReceivedEmail;
use App\Service\Factory\ReceivedEmailFactory;
use App\Service\Mailcow\MessageCopier;
use App\Service\N8nEmailWebhook;
use App\Service\Realtime\ReceivedEmailRealtimeNotifier;
use Doctrine\ORM\EntityManagerInterface;

class CreateReceivedEmailHandler
{
    public function __construct(
        private ReceivedEmailFactory $receivedEmailFactory,
        private EntityManagerInterface $entityManager,
        private ReceivedEmailRealtimeNotifier $receivedEmailRealtimeNotifier,
        private MessageCopier $mailcow,
        private N8nEmailWebhook $n8n,
    ) {
    }

    public function create(CreateEmailRequestDto $createEmailRequestDto): ReceivedEmail
    {
        $receivedEmail = $this->receivedEmailFactory->createFromDto($createEmailRequestDto);
        if ($this->mailcow->supports($receivedEmail)) {
            $receivedEmail->scheduleMailcowCopy(new \DateTimeImmutable());
        }

        $this->entityManager->persist($receivedEmail);
        if ($this->n8n->isConfigured()) {
            $this->entityManager->persist(new N8nEmailDelivery($receivedEmail));
        }
        $this->entityManager->flush();

        $this->receivedEmailRealtimeNotifier->notify($receivedEmail);

        return $receivedEmail;
    }
}
