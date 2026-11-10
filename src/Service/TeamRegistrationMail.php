<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;

final class TeamRegistrationMail
{
    public function __construct(
        #[Autowire(env: 'default::MAIL4U_TEAM_SMTP_DSN')]
        private readonly ?string $dsn,
        #[Autowire(env: 'default::MAIL4U_TEAM_FROM')]
        private readonly ?string $sender,
        #[Autowire(env: 'default::MAIL4U_PUBLIC_URL')]
        private readonly ?string $publicUrl,
        #[Autowire(env: 'default::MAIL4U_TEAM_SIGNING_KEY')]
        private readonly ?string $signingKey,
    ) {
    }

    public function isConfigured(): bool
    {
        return strlen($this->signingKey ?? '') >= 32
            && filter_var($this->sender, FILTER_VALIDATE_EMAIL) !== false
            && preg_match('~\Ahttps://[a-z0-9.-]+(?::[0-9]+)?/?\z~i', $this->publicUrl ?? '') === 1
            && preg_match('~\Asmtps?://~i', $this->dsn ?? '') === 1;
    }

    public function send(string $email, string $confirmationPath): void
    {
        $link = rtrim($this->publicUrl, '/') . $this->signer()->sign($confirmationPath, new \DateInterval('PT30M'));
        $transport = Transport::fromDsn($this->dsn);
        if (!$transport instanceof EsmtpTransport) {
            throw new \InvalidArgumentException('Configure um transporte SMTP para o cadastro.');
        }
        $transport->setRequireTls(true);

        (new Mailer($transport))->send(
            (new Email())
                ->from($this->sender)
                ->to($email)
                ->subject('Confirme seu cadastro no Mail4u')
                ->text("Use o link abaixo para confirmar seu e-mail e escolher uma senha no Mail4u. Ele vale por 30 minutos.\n\n" . $link),
        );
    }

    public function verifies(string $requestUri): bool
    {
        return $this->isConfigured() && $this->signer()->check($requestUri);
    }

    private function signer(): UriSigner
    {
        return new UriSigner($this->signingKey, 'team_signature');
    }
}
