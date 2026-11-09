<?php

namespace App\Controller;

use App\Repository\DomainRepository;
use App\Repository\ReceivedEmailRepository;
use App\Repository\TemporaryEmailBoxRepository;
use App\Service\Client\ClientIpRetriever;
use App\Service\Factory\TemporaryEmailBoxFactory;
use App\Service\Handler\ReceivedEmail\ReceivedEmailsFetcher;
use App\Service\Mailcow\MailboxProvisioner;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;
use Psr\Cache\CacheItemPoolInterface;

final class ClientInboxController extends AbstractController
{
    public function __construct(private readonly string $displayTimezone)
    {
    }

    #[Route('/acessar-caixa', name: 'app_client_inbox_login', methods: ['GET', 'POST'])]
    public function login(
        Request $request,
        TemporaryEmailBoxRepository $inboxes,
        ClientIpRetriever $clientIp,
        CacheItemPoolInterface $cache,
    ): Response {
        $email = '';
        $error = null;
        $status = 200;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('client-inbox-login', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $email = strtolower(trim($request->request->getString('email')));
            $password = $request->request->getString('password');
            $attempts = $cache->getItem('client_inbox_login_' . hash('sha256', $clientIp->getClientIp($request)));
            $count = (int) $attempts->get();
            if ($count >= 15) {
                $error = 'Muitas tentativas. Aguarde alguns minutos e tente novamente.';
                $status = 429;
            } else {
                $box = strlen($email) <= 255 && filter_var($email, FILTER_VALIDATE_EMAIL)
                    ? $inboxes->findOneBy(['email' => $email])
                    : null;
                if ($box !== null && $box->isClientInbox()
                    && strlen($password) <= 72 && $box->verifiesAccessPassword($password)) {
                    $session = $request->getSession();
                    $session->migrate();
                    $session->set('client_inbox_access', [$box->getUuid(), $box->getAccessVersion()]);

                    return $this->redirectToRoute('app_client_inbox', ['uuid' => $box->getUuid()], 303);
                }

                $cache->save($attempts->set($count + 1)->expiresAfter(600));
                $error = 'E-mail ou senha inválidos.';
                $status = 403;
            }
        }

        $response = $this->render('client_inbox/login.html.twig', ['email' => $email, 'error' => $error], new Response(status: $status));
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    #[Route('/', name: 'app_home', methods: ['GET', 'POST'])]
    #[Route('/clientes', name: 'app_client_inboxes', methods: ['GET', 'POST'])]
    public function manage(
        Request $request,
        DomainRepository $domains,
        TemporaryEmailBoxFactory $factory,
        ClientIpRetriever $clientIp,
        SluggerInterface $slugger,
        EntityManagerInterface $entityManager,
        MailboxProvisioner $mailcow,
    ): Response {
        if ($request->isMethod('GET')) {
            $response = $this->render('client_inbox/manage.html.twig');
            $response->headers->set('Cache-Control', 'private, no-store');

            return $response;
        }

        if (!$this->isCsrfTokenValid('create-client-inbox', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $firstName = trim($request->request->getString('first_name'));
        $lastName = trim($request->request->getString('last_name'));
        $firstSlug = $slugger->slug(mb_substr($firstName, 0, 80), '.', 'pt')->lower()->toString();
        $lastSlug = $slugger->slug(mb_substr($lastName, 0, 80), '.', 'pt')->lower()->toString();
        if ($firstSlug === '' || $lastSlug === '' || mb_strlen($firstName) > 80 || mb_strlen($lastName) > 80) {
            return $this->render('client_inbox/manage.html.twig', [
                'error' => 'Informe um nome e um sobrenome válidos, de até 80 caracteres cada.',
            ], new Response(status: 422));
        }

        $domain = $domains->findOneActiveRandomNonPremiumDomain();
        if ($domain === null) {
            return $this->render('client_inbox/manage.html.twig', [
                'error' => 'Nenhum domínio de e-mail ativo está disponível.',
            ], new Response(status: 422));
        }

        $prefix = rtrim(substr($firstSlug, 0, 24), '.') . '.' . rtrim(substr($lastSlug, 0, 24), '.');
        $email = sprintf('%s@%s', $prefix, $domain->getDomain());
        $password = $this->generateAccessPassword();
        $box = $factory->create($email, $clientIp->getClientIp($request), null);
        $box->protectForClient(password_hash($password, PASSWORD_DEFAULT));
        if ($mailcow->supports($email)) {
            $box->scheduleMailcowProvision(new \DateTimeImmutable());
        }
        $entityManager->persist($box);

        try {
            $entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            $this->addFlash('error', 'Esse endereço já está reservado. Inclua mais um sobrenome para gerar um endereço diferente.');

            return $this->redirectToRoute('app_home', status: 303);
        }

        $this->addFlash('client_inbox_credentials', [
            'uuid' => $box->getUuid(),
            'email' => $email,
            'password' => $password,
            'expiresAt' => $box->getExpiresAt()?->setTimezone(new \DateTimeZone($this->displayTimezone))->format('d/m/Y'),
        ]);

        return $this->redirectToRoute('app_home', status: 303);
    }

    private function generateAccessPassword(): string
    {
        $words = [
            'Italia', 'Roma', 'Milao', 'Veneza', 'Pisa', 'Napoles', 'Turim', 'Verona',
            'Brisa', 'Lua', 'Sol', 'Mar', 'Lago', 'Neve', 'Vento', 'Nuvem',
            'Oliva', 'Limao', 'Cereja', 'Amora', 'Manga', 'Caju', 'Pera', 'Coco',
            'Cedro', 'Rosa', 'Jasmim', 'Tulipa', 'Lavanda', 'Lirio', 'Dalia', 'Lotus',
        ];
        $symbols = '#!@%';

        return sprintf('%s%s%06d%s',
            $words[random_int(0, count($words) - 1)],
            $words[random_int(0, count($words) - 1)],
            random_int(0, 999999),
            $symbols[random_int(0, strlen($symbols) - 1)],
        );
    }

    #[Route('/caixa/{uuid}', name: 'app_client_inbox', requirements: ['uuid' => '[0-9a-f-]{36}'], methods: ['GET', 'POST'])]
    public function open(Request $request, string $uuid, TemporaryEmailBoxRepository $inboxes, ReceivedEmailsFetcher $messages, ReceivedEmailRepository $receivedEmails, EntityManagerInterface $entityManager): Response
    {
        $box = $inboxes->findOneBy(['uuid' => $uuid]);
        if ($box === null || !$box->isClientInbox()) {
            throw $this->createNotFoundException();
        }

        $session = $request->getSession();
        $sessionKey = 'client_inbox_access';
        $accessVersion = $box->getAccessVersion();
        $unlocked = $session->get($sessionKey) === [$uuid, $accessVersion];
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('client-inbox-' . $uuid, $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            if ($request->request->getString('action') === 'mark-read') {
                if (!$unlocked) {
                    throw $this->createAccessDeniedException();
                }
                $message = $receivedEmails->findOneBy([
                    'id' => $request->request->getInt('message_id'),
                    'temporaryEmailBox' => $box,
                ]);
                if ($message === null) {
                    throw $this->createNotFoundException();
                }
                if ($message->getReadAt() === null) {
                    $message->setReadAt(new \DateTimeImmutable());
                    $entityManager->flush();
                }

                return new Response(status: 204);
            }

            $password = $request->request->getString('password');
            if ($request->request->getString('action') === 'change-password') {
                $newPassword = $request->request->getString('new_password');
                if (!$unlocked || strlen($password) > 72 || !$box->verifiesAccessPassword($password)) {
                    $this->addFlash('error', 'Confira a senha atual da caixa.');
                } elseif (strlen($newPassword) < 10 || strlen($newPassword) > 64 || trim($newPassword) !== $newPassword || str_contains($newPassword, "\0")) {
                    $this->addFlash('error', 'Use uma senha de 10 a 64 bytes, sem espaços nas extremidades.');
                } else {
                    $box->changeAccessPassword(password_hash($newPassword, PASSWORD_DEFAULT));
                    $entityManager->flush();
                    $session->migrate();
                    $session->set($sessionKey, [$uuid, $box->getAccessVersion()]);
                    $this->addFlash('success', 'Senha atualizada.');
                }

                return $this->redirectToRoute('app_client_inbox', ['uuid' => $uuid], 303);
            }

            if (strlen($password) > 72 || !$box->verifiesAccessPassword($password)) {
                return $this->render('client_inbox/open.html.twig', [
                    'box' => $box,
                    'error' => 'Senha incorreta.',
                    'unlocked' => false,
                ], new Response(status: 403));
            }

            $session->migrate();
            $session->set($sessionKey, [$uuid, $accessVersion]);

            return $this->redirectToRoute('app_client_inbox', ['uuid' => $uuid], 303);
        }

        $template = 'client_inbox/open.html.twig';
        $context = [
            'box' => $box,
            'unlocked' => $unlocked,
            'emails' => $unlocked ? $messages->fetchByTemporaryEmailBox($box) : [],
        ];
        $response = $request->query->getBoolean('messages_only')
            ? ($unlocked ? $this->renderBlock($template, 'message_rows', $context) : new Response(status: 403))
            : $this->render($template, $context);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
