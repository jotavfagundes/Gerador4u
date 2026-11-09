<?php

namespace App\Service\Mailcow;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class MailboxProvisioner
{
    private array $domains;

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire(env: 'default::MAILCOW_API_URL')]
        private ?string $url,
        #[Autowire(env: 'default::MAILCOW_API_KEY')]
        private ?string $apiKey,
        #[Autowire(env: 'default::MAILCOW_PROVISION_DOMAINS')]
        ?string $domains,
    ) {
        $this->domains = array_map('trim', explode(',', strtolower($domains ?? '')));
    }

    public function supports(string $email): bool
    {
        $domain = substr(strrchr($email, '@') ?: '', 1);

        return str_starts_with($this->url ?? '', 'https://')
            && $this->apiKey !== null && $this->apiKey !== ''
            && $domain !== '' && in_array(strtolower($domain), $this->domains, true);
    }

    public function provision(string $email): string
    {
        [$localPart, $domain] = explode('@', $email, 2);
        $password = 'Aa9!' . bin2hex(random_bytes(24));
        $response = $this->httpClient->request('POST', rtrim($this->url, '/') . '/api/v1/add/mailbox', [
            'headers' => ['X-API-Key' => $this->apiKey],
            'json' => [
                'local_part' => $localPart,
                'domain' => $domain,
                'name' => $localPart,
                'active' => '1',
                'smtp_access' => '0',
                'password' => $password,
                'password2' => $password,
            ],
            'max_redirects' => 0,
            'max_duration' => 5,
            'extra' => ['trace_content' => false],
        ])->toArray();

        foreach ($response as $result) {
            $message = $result['msg'][0] ?? null;
            if ($message === 'mailbox_added' && ($result['type'] ?? null) === 'success') {
                return 'created';
            }
            if ($message === 'object_exists') {
                return 'address-already-in-use';
            }
        }

        throw new \RuntimeException('Mailcow did not confirm mailbox provisioning.');
    }
}
