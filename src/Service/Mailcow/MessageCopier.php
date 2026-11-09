<?php

namespace App\Service\Mailcow;

use App\Entity\ReceivedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Email;

final class MessageCopier
{
    private array $targets;

    public function __construct(
        #[Autowire(env: 'default::MAILCOW_SMTP_URL')]
        private ?string $url,
        #[Autowire(env: 'default::MAILCOW_SMTP_USER')]
        private ?string $user,
        #[Autowire(env: 'default::MAILCOW_SMTP_PASSWORD')]
        #[\SensitiveParameter]
        private ?string $password,
        #[Autowire(env: 'default::MAILCOW_COPY_TARGETS')]
        ?string $targets,
    ) {
        $this->targets = array_map('trim', explode(',', strtolower($targets ?? '')));
    }

    public function supports(ReceivedEmail $message): bool
    {
        $recipient = strtolower($message->getRealTo());
        $domain = substr(strrchr($recipient, '@') ?: '', 1);

        return str_starts_with($this->url ?? '', 'smtp://')
            && filter_var($this->user, FILTER_VALIDATE_EMAIL)
            && $this->password !== null && $this->password !== ''
            && $message->getTemporaryEmailBox()?->isClientInbox() === true
            && filter_var($recipient, FILTER_VALIDATE_EMAIL)
            && $recipient !== strtolower($this->user)
            && strtolower($message->getRealFrom()) !== strtolower($this->user)
            && strtolower($message->getFromAddress() ?? '') !== strtolower($this->user)
            && (in_array($recipient, $this->targets, true) || in_array($domain, $this->targets, true));
    }

    public function copy(ReceivedEmail $message): void
    {
        $text = $message->getMetadata()['text'] ?? null;
        $mail = (new Email())
            ->from($this->user)
            ->to($message->getRealTo())
            ->subject($message->getSubject() ?: '(Sem assunto)')
            ->date($message->getCreatedAt() ?? new \DateTimeImmutable());
        $mail->getHeaders()->addIdHeader('Message-ID', 'mail4u-' . $message->getUuid() . strrchr($this->user, '@'));
        $sender = $message->getFromAddress() ?: $message->getRealFrom();
        if (filter_var($sender, FILTER_VALIDATE_EMAIL)) {
            $mail->getHeaders()->addTextHeader('X-Mail4u-Original-From', $sender);
        }
        if (is_string($text) && $text !== '') {
            $mail->text($text);
        }
        if ($message->getHtml()) {
            $mail->html($message->getHtml());
        } elseif ($mail->getTextBody() === null) {
            $mail->text('Mensagem recebida sem corpo.');
        }

        $payload = $mail->toString();
        $stream = fopen('php://temp', 'w+b');
        try {
            fwrite($stream, $payload);
            rewind($stream);
            $smtp = curl_init($this->url);
            curl_setopt_array($smtp, [
                CURLOPT_USERNAME => $this->user,
                CURLOPT_PASSWORD => $this->password,
                CURLOPT_USE_SSL => CURLUSESSL_ALL,
                CURLOPT_MAIL_FROM => '<' . $this->user . '>',
                CURLOPT_MAIL_RCPT => ['<' . $message->getRealTo() . '>'],
                CURLOPT_UPLOAD => true,
                CURLOPT_INFILE => $stream,
                CURLOPT_INFILESIZE => strlen($payload),
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_TIMEOUT => 45,
                CURLOPT_RETURNTRANSFER => true,
            ]);
            if (curl_exec($smtp) === false || curl_getinfo($smtp, CURLINFO_RESPONSE_CODE) !== 250) {
                throw new \RuntimeException(sprintf(
                    'SMTP rejected the copy (curl %d, SMTP %d).',
                    curl_errno($smtp),
                    curl_getinfo($smtp, CURLINFO_RESPONSE_CODE),
                ));
            }
        } finally {
            fclose($stream);
        }
    }
}
