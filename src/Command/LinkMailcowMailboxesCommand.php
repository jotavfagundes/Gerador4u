<?php

namespace App\Command;

use App\Repository\ReceivedEmailRepository;
use App\Repository\TemporaryEmailBoxRepository;
use App\Service\Factory\TemporaryEmailBoxFactory;
use App\Service\Mailcow\MailboxProvisioner;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

#[AsCommand(name: 'app:link-mailcow-mailboxes', description: 'Links recent incoming messages to verified Mailcow mailboxes.')]
final class LinkMailcowMailboxesCommand extends Command
{
    public function __construct(
        private readonly MailboxProvisioner $mailcow,
        private readonly ReceivedEmailRepository $messages,
        private readonly TemporaryEmailBoxRepository $mailboxes,
        private readonly TemporaryEmailBoxFactory $factory,
        private readonly EntityManagerInterface $manager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $domains = $this->mailcow->getLinkableDomains();
        if ($domains === []) {
            return Command::SUCCESS;
        }

        foreach ($this->messages->findUnlinkedForMailcow($domains, new \DateTimeImmutable()) as $message) {
            $id = $message->getId();
            $now = new \DateTimeImmutable();
            if (!$this->messages->claimMailcowLink($id, $now)) {
                continue;
            }

            $address = strtolower($message->getRealTo());
            if (strlen($address) > 255 || !filter_var($address, FILTER_VALIDATE_EMAIL) || !$this->mailcow->supports($address)) {
                $this->messages->deferMailcowLink($id, $now->modify('+24 hours'));
                continue;
            }

            $box = $this->mailboxes->findOneBy(['email' => $message->getRealTo()])
                ?? $this->mailboxes->findOneBy(['email' => $address]);
            if ($box !== null && (!$box->isClientInbox() || $box->getExpiresAt() <= $now)) {
                $this->messages->deferMailcowLink($id, $now->modify('+24 hours'));
                continue;
            }

            if ($box === null) {
                try {
                    $active = $this->mailcow->hasActiveMailbox($address);
                } catch (ExceptionInterface $exception) {
                    if (!$exception instanceof ClientExceptionInterface || $exception->getResponse()->getStatusCode() !== 404) {
                        $output->writeln(sprintf('Mailcow mailbox link %d: lookup failed, will retry.', $id));
                        continue;
                    }
                    $active = false;
                }

                if (!$active) {
                    $this->messages->deferMailcowLink($id, $now->modify('+1 hour'));
                    continue;
                }

                $box = $this->factory->create($address, 'mailcow', null);
                $box->protectForClient(password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT));
                $this->manager->persist($box);
            }

            $message->setTemporaryEmailBox($box);
            try {
                $this->manager->flush();
            } catch (UniqueConstraintViolationException) {
                $output->writeln(sprintf('Mailcow mailbox link %d: concurrent creation, will retry.', $id));

                return Command::SUCCESS;
            }
            $output->writeln(sprintf('Mailcow mailbox link %d: linked.', $id));
        }

        return Command::SUCCESS;
    }
}
