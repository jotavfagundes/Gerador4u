<?php

namespace App\Controller;

use App\Entity\ReceivedEmail;
use App\Repository\ReceivedEmailRepository;
use App\Repository\TemporaryEmailBoxRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class MailboxReadApiController extends AbstractController
{
    #[Route('/integracao/v1/mensagens', name: 'mailbox_read_api', methods: ['GET'])]
    public function __invoke(
        Request $request,
        TemporaryEmailBoxRepository $mailboxes,
        ReceivedEmailRepository $messages,
        #[Autowire(env: 'default::MAIL4U_READ_API_TOKEN')]
        ?string $token,
    ): JsonResponse {
        $headers = ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'];
        if ($token === null || strlen($token) < 32) {
            return new JsonResponse(['error' => 'API de leitura indisponível.'], 503, $headers);
        }

        $authorization = $request->headers->get('Authorization', '');
        if (!preg_match('/\ABearer ([^\s]+)\z/i', $authorization, $matches) || !hash_equals($token, $matches[1])) {
            return new JsonResponse(['error' => 'Credencial inválida.'], 401, $headers + ['WWW-Authenticate' => 'Bearer']);
        }

        $query = $request->query->all();
        $email = $query['email'] ?? null;
        $afterId = filter_var($query['after_id'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if (!is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL) || $afterId === false) {
            return new JsonResponse(['error' => 'Informe um e-mail válido e um after_id inteiro não negativo.'], 400, $headers);
        }

        $mailbox = $mailboxes->findOneBy(['email' => strtolower($email)]);
        if ($mailbox === null) {
            return new JsonResponse(['error' => 'Caixa de e-mail não encontrada.'], 404, $headers);
        }

        $page = $messages->createQueryBuilder('message')
            ->where('message.temporaryEmailBox = :mailbox')
            ->andWhere('message.id > :afterId')
            ->setParameter('mailbox', $mailbox)
            ->setParameter('afterId', $afterId)
            ->orderBy('message.id', 'ASC')
            ->setMaxResults(21)
            ->getQuery()
            ->getResult();

        $items = array_map(static function (ReceivedEmail $message): array {
            $text = $message->getMetadata()['text'] ?? null;

            return [
                'id' => $message->getId(),
                'from' => $message->getRealFrom(),
                'to' => $message->getRealTo(),
                'subject' => $message->getSubject(),
                'received_at' => $message->getCreatedAt()?->format(DATE_ATOM),
                'text' => is_string($text) ? $text : null,
                'html' => $message->getHtml(),
            ];
        }, array_slice($page, 0, 20));

        $response = new JsonResponse(null, 200, $headers);
        $response->setEncodingOptions(JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_INVALID_UTF8_SUBSTITUTE);

        return $response->setData([
            'mailbox' => $mailbox->getEmail(),
            'messages' => $items,
            'next_after_id' => $items === [] ? $afterId : $items[array_key_last($items)]['id'],
            'has_more' => count($page) > 20,
        ]);
    }
}
