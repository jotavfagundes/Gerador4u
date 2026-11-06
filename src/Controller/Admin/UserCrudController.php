<?php

namespace App\Controller\Admin;

use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Validator\Constraints\Image;

class UserCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->setDisabled(),
            TextField::new('email', 'E-mail'),
            TextField::new('profilePhotoUpload', 'Foto de perfil')
                ->onlyOnForms()
                ->setFormType(FileType::class)
                ->setRequired(false)
                ->setHelp('Envie uma imagem JPG, PNG ou WebP de até 512 KB.')
                ->setFormTypeOption('constraints', [
                    new Image(maxSize: '512k', mimeTypes: ['image/jpeg', 'image/png', 'image/webp']),
                ]),
            TextField::new('newPassword', 'Nova senha')->hideOnIndex(),
            TextField::new('password', 'Senha')->setDisabled()->hideOnIndex(),
            ChoiceField::new('roles', 'Perfis de acesso')
                ->setChoices(array_combine(User::ROLES, User::ROLES))
                ->allowMultipleChoices()
                ->renderExpanded()
                ->renderAsBadges(),
            AssociationField::new('temporaryEmailBoxes', 'Caixas temporárias'),
        ];
    }
}
