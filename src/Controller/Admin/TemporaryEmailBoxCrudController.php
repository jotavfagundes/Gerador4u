<?php

namespace App\Controller\Admin;

use App\Controller\Admin\Filter\MailboxExpiryFilter;
use App\Entity\TemporaryEmailBox;
use App\Repository\ReceivedEmailRepository;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Validator\Constraints as Assert;

class TemporaryEmailBoxCrudController extends AbstractCrudController
{
    public function __construct(private ReceivedEmailRepository $messages, private AdminUrlGenerator $urls)
    {
    }

    public static function getEntityFqcn(): string
    {
        return TemporaryEmailBox::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->setDisabled(),
            TextField::new('email', 'E-mail')->setTemplatePath('admin/field/mailbox_email.html.twig'),
            TextField::new('uuid', 'UUID'),
            TextField::new('creatorIp', 'IP de criação'),
            TextField::new('countryCode', 'Código do país'),
            AssociationField::new('owner', 'Responsável'),
            AssociationField::new('receivedEmails', 'E-mails recebidos')
                ->hideOnForm(),
            DateTimeField::new('createdAt', 'Criado em')->setDisabled(),
            DateTimeField::new('expiresAt', 'Recebe até')->hideOnForm(),
            TextField::new('latestMessage', 'Última mensagem')->hideOnForm()->setSortable(false)
                ->formatValue(function ($value, TemporaryEmailBox $box): string {
                    $message = $this->messages->findOneBy(['temporaryEmailBox' => $box], ['createdAt' => 'DESC']);

                    return $message?->getCreatedAt()?->format('d/m/Y H:i') ?? 'Sem mensagens';
                }),
            DateTimeField::new('lastAccessedAt', 'Último acesso em')->setDisabled(),
            TextField::new('newAccessPassword', 'Redefinir senha da caixa')
                ->onlyWhenUpdating()
                ->setFormType(PasswordType::class)
                ->setRequired(false)
                ->setHelp('Para caixas de clientes. Deixe em branco para manter a senha atual.')
                ->setFormTypeOption('constraints', [
                    new Assert\Length(min: 10, max: 64, countUnit: Assert\Length::COUNT_BYTES),
                    new Assert\Regex(pattern: '/\A(?!\s)[^\x00]+(?<!\s)\z/', message: 'Use uma senha sem espaços nas extremidades.'),
                ]),
        ];
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(MailboxExpiryFilter::new())
            ->add(TextFilter::new('countryCode', 'Código do país'));
    }

    public function configureActions(Actions $actions): Actions
    {
        $impersonateAction = Action::new('impersonate', 'Abrir caixa como usuário', 'fas fa-user-secret')
            ->linkToUrl(function (TemporaryEmailBox $emailBox): string {
                return '/?' . http_build_query([
                    'impersonate_email' => $emailBox->getEmail(),
                    'impersonate_uuid' => $emailBox->getUuid(),
                ]);
            });

        $resetPassword = Action::new('resetMailboxPassword', 'Redefinir senha', 'fas fa-key')
            ->displayIf(static fn (TemporaryEmailBox $box): bool => $box->isClientInbox())
            ->linkToUrl(fn (TemporaryEmailBox $box): string => (clone $this->urls)
                ->unsetAll()
                ->setController(self::class)
                ->setAction(Action::EDIT)
                ->setEntityId($box->getId())
                ->generateUrl() . '#TemporaryEmailBox_newAccessPassword');

        return $actions
            ->add(Crud::PAGE_INDEX, $resetPassword)
            ->add(Crud::PAGE_INDEX, $impersonateAction)
            ->add(Crud::PAGE_DETAIL, $impersonateAction);
    }


    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Caixa de e-mail')
            ->setEntityLabelInPlural('Caixas de e-mail')
            ->setSearchFields(['email'])
            ->setDefaultSort(['createdAt' => 'DESC']);
    }
}
