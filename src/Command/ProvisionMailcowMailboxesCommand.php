<?php

namespace App\Command;

use App\Repository\TemporaryEmailBoxRepository;
use App\Service\Mailcow\MailboxProvisioner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:provision-mailcow-mailboxes', description: 'Creates pending Mailcow mailboxes.')]
final class ProvisionMailcowMailboxesCommand extends Command
{
    public function __construct(
        private TemporaryEmailBoxRepository $inboxes,
        private MailboxProvisioner $mailcow,
        private EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = new \DateTimeImmutable();
        foreach ($this->inboxes->findDueMailcowProvisions($now) as $box) {
            $email = $box->getEmail();
            if ($email === null || !$this->mailcow->supports($email)) {
                $box->scheduleMailcowProvision($now->modify('+1 hour'));
                continue;
            }

            try {
                $result = $this->mailcow->provision($email);
                $box->scheduleMailcowProvision(null);
                $output->writeln(sprintf('Mailcow mailbox %d: %s.', $box->getId(), $result));
            } catch (\Exception) {
                $box->scheduleMailcowProvision($now->modify('+15 minutes'));
                $output->writeln(sprintf('Mailcow mailbox %d: provisioning failed, will retry.', $box->getId()));
            }
        }
        $this->entityManager->flush();

        return Command::SUCCESS;
    }
}
