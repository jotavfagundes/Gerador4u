<?php

namespace App\Command;

use App\Repository\ReceivedEmailRepository;
use App\Service\Mailcow\MailboxProvisioner;
use App\Service\Mailcow\MessageCopier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:copy-mailcow-messages', description: 'Delivers pending message copies to Mailcow.')]
final class CopyMailcowMessagesCommand extends Command
{
    public function __construct(
        private ReceivedEmailRepository $messages,
        private MessageCopier $mailcow,
        private MailboxProvisioner $mailboxes,
        private EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = new \DateTimeImmutable();
        foreach ($this->messages->findDueMailcowCopies($now) as $message) {
            $id = $message->getId();
            if (!$this->messages->claimMailcowCopy($id, $now) || !$this->mailcow->supports($message)) {
                continue;
            }
            try {
                if (!$this->mailboxes->hasActiveMailbox($message->getRealTo())) {
                    $output->writeln(sprintf('Mailcow copy %d: waiting for an active mailbox.', $id));
                    continue;
                }
                $this->mailcow->copy($message);
            } catch (\Exception) {
                $output->writeln(sprintf('Mailcow copy %d: failed, will retry.', $id));
                continue;
            }

            $message->scheduleMailcowCopy(null);
            $this->entityManager->flush();
            $output->writeln(sprintf('Mailcow copy %d: delivered.', $id));
        }

        return Command::SUCCESS;
    }
}
