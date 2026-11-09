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
            if (!$this->messages->claimMailcowCopy($id, new \DateTimeImmutable())) {
                continue;
            }
            try {
                $status = 'queued';
                if ($this->mailcow->supports($message) && $this->mailboxes->hasActiveMailbox($message->getRealTo())) {
                    $this->mailcow->copy($message);
                    $status = 'accepted';
                }
            } catch (\Exception) {
                $status = 'failed';
            }

            $message->recordMailcowCopyResult($status);
            $this->entityManager->flush();
            $output->writeln(sprintf('Mailcow copy %d: %s.', $id, match ($status) {
                'accepted' => 'accepted by SMTP',
                'failed' => 'failed, will retry',
                'queued' => 'waiting for an active mailbox and copy configuration',
            }));
        }

        return Command::SUCCESS;
    }
}
