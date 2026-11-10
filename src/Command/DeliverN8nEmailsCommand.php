<?php

namespace App\Command;

use App\Repository\N8nEmailDeliveryRepository;
use App\Service\N8nEmailWebhook;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

#[AsCommand(name: 'app:deliver-n8n-emails', description: 'Sends queued email deliveries to n8n.')]
final class DeliverN8nEmailsCommand extends Command
{
    private const MAX_FAILURES = 12;

    public function __construct(
        private readonly N8nEmailDeliveryRepository $deliveries,
        private readonly N8nEmailWebhook $webhook,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->webhook->isConfigured()) {
            $output->writeln('Configure MAIL4U_N8N_WEBHOOK_URL with an HTTPS URL.');

            return Command::INVALID;
        }

        foreach ($this->deliveries->findDue(new \DateTimeImmutable()) as $delivery) {
            $id = $delivery->getId();
            $now = new \DateTimeImmutable();
            $leaseUntil = $now->modify('+5 minutes');
            if (!$this->deliveries->claim($id, $now, $leaseUntil)) {
                continue;
            }

            $result = 'transport failure';
            try {
                $status = $this->webhook->deliver($delivery->getEmail());
                if ($status >= 200 && $status < 300) {
                    $this->deliveries->markDelivered($id, $leaseUntil);
                    $output->writeln(sprintf('n8n delivery %d: accepted (HTTP %d).', $id, $status));
                    continue;
                }
                $result = sprintf('HTTP %d', $status);
            } catch (TransportExceptionInterface) {
                // The exception can contain the webhook URL, so keep logs free of its details.
            }

            $failures = $delivery->getFailedAttempts() + 1;
            $delay = min(900, 5 * 2 ** ($failures - 1));
            $retryAt = $failures < self::MAX_FAILURES ? new \DateTimeImmutable("+$delay seconds") : null;
            $this->deliveries->markFailed($id, $leaseUntil, $retryAt);
            $output->writeln(sprintf('n8n delivery %d: %s, %s.', $id, $result, $retryAt ? 'will retry' : 'retry limit reached'));
        }

        return Command::SUCCESS;
    }
}
