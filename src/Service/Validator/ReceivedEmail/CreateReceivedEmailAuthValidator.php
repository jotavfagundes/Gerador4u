<?php

namespace App\Service\Validator\ReceivedEmail;

use App\Exception\ReceivedEmail\UnauthorizedToCreateReceivedEmailException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::CONTROLLER)]
final class CreateReceivedEmailAuthValidator
{
    public function __construct(
        #[Autowire(env: 'default::CREATE_RECEIVED_EMAIL_API_AUTHORIZATION_KEY')]
        private readonly ?string $key,
    ) {
    }

    public function __invoke(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        if ($request->attributes->get('_route') !== 'api_create_email') {
            return;
        }

        if ($this->key === null || strlen($this->key) < 32
            || !hash_equals($this->key, $request->headers->get('Authorization', ''))
        ) {
            throw new UnauthorizedToCreateReceivedEmailException();
        }
    }
}
