<?php

namespace App\Controller\Admin;

use App\Entity\TemporaryEmailBox;
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
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Validator\Constraints as Assert;

class TemporaryEmailBoxCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return TemporaryEmailBox::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->setDisabled(),
            TextField::new('email', 'E-mail'),
            TextField::new('uuid', 'UUID'),
            TextField::new('creatorIp', 'IP de criação'),
            TextField::new('countryCode', 'Código do país'),
            AssociationField::new('owner', 'Responsável'),
            AssociationField::new('receivedEmails', 'E-mails recebidos')
                ->hideOnForm(),
            DateTimeField::new('createdAt', 'Criado em')->setDisabled(),
            DateTimeField::new('lastAccessedAt', 'Último acesso em')->setDisabled(),
            TextField::new('newAccessPassword', 'Nova senha da caixa')
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

        return $actions
            ->add(Crud::PAGE_INDEX, $impersonateAction)
            ->add(Crud::PAGE_DETAIL, $impersonateAction);
    }


    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Caixa de e-mail')
            ->setEntityLabelInPlural('Caixas de e-mail')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }
}
