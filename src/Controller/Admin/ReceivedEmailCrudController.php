<?php

namespace App\Controller\Admin;

use App\Entity\ReceivedEmail;
use App\Repository\TemporaryEmailBoxRepository;
use App\Service\Factory\TemporaryEmailBoxFactory;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class ReceivedEmailCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return ReceivedEmail::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->setDisabled(),
            TextField::new('realFrom', 'Remetente original'),
            TextField::new('uuid', 'UUID'),
            TextField::new('realTo', 'Destinatário original'),
            TextField::new('subject', 'Assunto'),
            TextField::new('fromName', 'Nome do remetente'),
            TextField::new('fromAddress', 'Endereço do remetente'),
            TextField::new('hasAssignedTemporaryEmailBox', 'Tem caixa temporária vinculada'),
            TextField::new('toMultipleString', 'Destinatários')->hideOnIndex(),
            TextField::new('bccMultipleString', 'Destinatários em cópia oculta')->hideOnIndex(),
            DateTimeField::new('createdAt', 'Criado em'),
            TextField::new('mailcowCopyState', 'Cópia no Mailcow')
                ->hideOnForm()->setSortable(false)->setTemplatePath('admin/field/mailcow_copy_state.html.twig'),
            DateTimeField::new('mailcowCopyAttemptedAt', 'Última tentativa da cópia')->hideOnForm(),
            DateTimeField::new('readAt', 'Lido em'),
            DateTimeField::new('subjectReadAt', 'Assunto lido em'),
            TextField::new('metadataString', 'Metadados')->hideOnIndex(),
        ];
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->overrideTemplate('crud/detail', 'admin/received_email/detail.html.twig')
            ->setPageTitle(Crud::PAGE_DETAIL, 'E-mail recebido')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(TextFilter::new('realTo', 'Destinatário original'));
    }

    public function configureActions(Actions $actions): Actions
    {
        $viewHtml = Action::new('viewHtml', 'Abrir em nova aba', 'fas fa-external-link-alt')
            ->linkToUrl(function ($entity) {
                return $this->generateUrl('admin_received_email_view_html', [
                    'id' => $entity->getId(),
                ]);
            })
            ->setHtmlAttributes(['target' => '_blank', 'rel' => 'noopener noreferrer']);

        return $actions
            ->disable(Action::NEW, Action::EDIT)
            ->add(Crud::PAGE_INDEX, $viewHtml)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->update(Crud::PAGE_INDEX, Action::DETAIL, static fn (Action $action): Action => $action->setLabel('Ver e-mail'))
        ;
    }

    #[Route('/admin/received-email/{id}/view-html', name: 'admin_received_email_view_html')]
    public function viewHtml(ReceivedEmail $email): Response
    {
        return $this->render('email_box/view.html.twig', [
            'html' => $email->getHtml(),
            'text' => $email->getMetadata()['text'] ?? null,
        ], new Response(headers: ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']));
    }

    #[Route('/admin/received-email/{id}/link-mailbox', name: 'admin_received_email_link_mailbox', requirements: ['id' => '\\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function linkMailbox(
        #[MapEntity(id: 'id')] ReceivedEmail $email,
        Request $request,
        TemporaryEmailBoxRepository $mailboxes,
        TemporaryEmailBoxFactory $factory,
        EntityManagerInterface $manager,
        AdminUrlGenerator $urls,
    ): Response {
        $id = $email->getId();
        if (!$this->isCsrfTokenValid('link-mailbox-' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $detail = (clone $urls)->unsetAll()
            ->setDashboard(DashboardController::class)
            ->setController(self::class)
            ->setAction(Action::DETAIL)
            ->setEntityId($id)
            ->generateUrl();

        if ($email->getTemporaryEmailBox() !== null) {
            return $this->redirect($detail, Response::HTTP_SEE_OTHER);
        }

        $address = strtolower($email->getRealTo());
        if (strlen($address) > 255 || !filter_var($address, FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('warning', 'O destinatário original não é um endereço válido.');

            return $this->redirect($detail, Response::HTTP_SEE_OTHER);
        }

        $box = $mailboxes->findOneBy(['email' => $address]);
        if ($box !== null && (!$box->isClientInbox() || $box->getExpiresAt() <= new \DateTimeImmutable())) {
            $this->addFlash('warning', 'Já existe uma caixa com esse endereço sem proteção por senha ou com prazo vencido.');

            return $this->redirect($detail, Response::HTTP_SEE_OTHER);
        }

        $password = null;
        if ($box === null) {
            $password = bin2hex(random_bytes(16));
            $box = $factory->create($address, $request->getClientIp() ?? 'unknown', null);
            $box->protectForClient(password_hash($password, PASSWORD_DEFAULT));
            $manager->persist($box);
        }
        $email->setTemporaryEmailBox($box);

        try {
            $manager->flush();
        } catch (UniqueConstraintViolationException) {
            $this->addFlash('warning', 'Esse endereço acabou de ser cadastrado. Tente vincular a mensagem novamente.');

            return $this->redirect($detail, Response::HTTP_SEE_OTHER);
        }

        if ($password !== null) {
            return $this->render('admin/received_email/linked_mailbox.html.twig', [
                'box' => $box,
                'password' => $password,
                'detailUrl' => $detail,
            ], new Response(headers: ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']));
        }
        $this->addFlash('success', 'Mensagem vinculada à caixa no Mail4u.');

        return $this->redirect($detail, Response::HTTP_SEE_OTHER);
    }
}
