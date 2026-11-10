<?php

namespace App\Controller\Admin;

use App\Entity\ReceivedEmail;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

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
            TextField::new('html', 'HTML')->hideOnIndex(),
            TextField::new('metadataString', 'Metadados')->hideOnIndex(),
        ];
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(TextFilter::new('realTo', 'Destinatário original'));
    }

    public function configureActions(Actions $actions): Actions
    {
        $viewHtml = Action::new('viewHtml', 'Ver HTML', 'fas fa-file-alt')
            ->linkToUrl(function ($entity) {
                return $this->generateUrl('admin_received_email_view_html', [
                    'id' => $entity->getId(),
                ]);
            })
            ->setHtmlAttributes(['target' => '_blank']);

        return $actions
            ->disable(Action::NEW, Action::EDIT)
            ->add(Crud::PAGE_INDEX, $viewHtml)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
        ;
    }

    #[Route('/admin/received-email/{id}/view-html', name: 'admin_received_email_view_html')]
    public function viewHtml(ReceivedEmail $email): Response
    {
        return $this->render('email_box/view.html.twig', [
            'html' => $email->getHtml(),
        ], new Response(headers: ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']));
    }
}
