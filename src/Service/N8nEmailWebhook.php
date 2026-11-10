<?php

namespace App\Service;

use App\Entity\ReceivedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class N8nEmailWebhook
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'default::MAIL4U_N8N_WEBHOOK_URL')]
        private readonly ?string $url,
    ) {
    }

    public function isConfigured(): bool
    {
        return str_starts_with($this->url ?? '', 'https://')
            && filter_var($this->url, FILTER_VALIDATE_URL) !== false;
    }

    public function deliver(ReceivedEmail $email): int
    {
        $text = $email->getMetadata()['text'] ?? null;
        $response = $this->httpClient->request('POST', $this->url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Idempotency-Key' => $email->getUuid(),
            ],
            'body' => json_encode([
                'id' => $email->getId(),
                'uuid' => $email->getUuid(),
                'from' => $email->getRealFrom(),
                'from_name' => $email->getFromName(),
                'from_address' => $email->getFromAddress(),
                'to' => $email->getRealTo(),
                'mailbox' => $email->getTemporaryEmailBox()?->getEmail(),
                'subject' => $email->getSubject(),
                'received_at' => $email->getCreatedAt()?->format(DATE_ATOM),
                'text' => is_string($text) ? $text : null,
                'html' => $email->getHtml(),
            ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
            'max_redirects' => 0,
            'max_duration' => 10,
        ]);

        $status = $response->getStatusCode();
        $response->cancel();

        return $status;
    }
}
