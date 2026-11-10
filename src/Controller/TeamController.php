<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\TemporaryEmailBoxRepository;
use App\Repository\UserRepository;
use App\Service\Client\ClientIpRetriever;
use App\Service\TeamRegistrationMail;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class TeamController extends AbstractController
{
    private const PRIVATE_HEADERS = ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer'];

    #[Route('/minhas-caixas', name: 'app_team_inboxes', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function inboxes(TemporaryEmailBoxRepository $mailboxes): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('team/inboxes.html.twig', [
            'boxes' => $mailboxes->findClientInboxesByOwner($user),
        ], new Response(headers: self::PRIVATE_HEADERS));
    }

    #[Route('/cadastro', name: 'app_team_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        UserRepository $users,
        TeamRegistrationMail $mail,
        ClientIpRetriever $clientIp,
        #[Autowire(service: 'limiter.team_registration')]
        RateLimiterFactoryInterface $limiter,
    ): Response {
        $email = '';
        $error = null;
        $sent = false;
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('team-register', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }
            $email = strtolower(trim($request->request->getString('email')));
            if (!$limiter->create('ip:' . $clientIp->getClientIp($request))->consume()->isAccepted()) {
                $error = 'Muitas solicitações. Aguarde alguns minutos e tente novamente.';
                $status = Response::HTTP_TOO_MANY_REQUESTS;
            } elseif (!User::isTeamEmail($email)) {
                $error = 'Informe um e-mail @cidadania4u.com.br.';
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            } elseif (!$mail->isConfigured()) {
                $error = 'O cadastro está indisponível. Fale com a equipe de Tecnologia.';
                $status = Response::HTTP_SERVICE_UNAVAILABLE;
            } else {
                try {
                    if (!$users->hasEmail($email)
                        && $limiter->create('email:' . $email)->consume()->isAccepted()
                    ) {
                        $mail->send($email, $this->generateUrl('app_team_confirm', ['team_email' => $email]));
                    }
                    $sent = true;
                } catch (TransportExceptionInterface|\InvalidArgumentException) {
                    $error = 'Não foi possível enviar a confirmação. Tente novamente mais tarde.';
                    $status = Response::HTTP_SERVICE_UNAVAILABLE;
                }
            }
        }

        return $this->render('team/register.html.twig', [
            'email' => $email,
            'error' => $error,
            'sent' => $sent,
        ], new Response(status: $status, headers: self::PRIVATE_HEADERS));
    }

    #[Route('/cadastro/confirmar', name: 'app_team_confirm', methods: ['GET', 'POST'])]
    public function confirm(
        Request $request,
        TeamRegistrationMail $mail,
        UserRepository $users,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $manager,
    ): Response {
        $email = $request->query->getString('team_email');
        $valid = User::isTeamEmail($email) && $mail->verifies($request->getRequestUri());
        $error = null;
        $status = $valid ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST;
        if ($valid && $users->hasEmail($email)) {
            return $this->loginRedirect();
        }

        if ($valid && $request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('team-confirm-' . $email, $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }
            $password = $request->request->getString('password');
            if (strlen($password) > 72 || mb_strlen($password) < 12 || str_contains($password, "\0")) {
                $error = 'Use uma senha com pelo menos 12 caracteres e até 72 bytes.';
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            } else {
                $user = (new User())->setEmail($email);
                $user->setPassword($hasher->hashPassword($user, $password));
                $manager->persist($user);
                try {
                    $manager->flush();
                } catch (UniqueConstraintViolationException) {
                    return $this->loginRedirect();
                }

                $this->addFlash('team_registered', 'Conta criada. Entre com seu e-mail e sua senha.');

                return $this->loginRedirect();
            }
        }

        return $this->render('team/confirm.html.twig', [
            'valid' => $valid,
            'email' => $email,
            'error' => $error,
        ], new Response(status: $status, headers: self::PRIVATE_HEADERS));
    }

    private function loginRedirect(): RedirectResponse
    {
        return new RedirectResponse($this->generateUrl('app_login'), 303, self::PRIVATE_HEADERS);
    }
}
