<?php

namespace App\Controller\Admin;

use App\Entity\Domain;
use App\Repository\ReceivedEmailRepository;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class DomainCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly ReceivedEmailRepository $receivedEmailRepository,
        private readonly string $displayTimezone,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Domain::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->setDisabled(),
            TextField::new('domain', 'Domínio'),
            TextField::new('emailCount', 'Total de e-mails')
                ->onlyOnIndex()
                ->formatValue(function ($value, Domain $entity) {
                    return $this->receivedEmailRepository->countByDomain($entity->getDomain());
                }),
            DateTimeField::new('activeUntil', 'Ativo até')
                ->setFormTypeOption('view_timezone', $this->displayTimezone),
            BooleanField::new('automaticGenerationEnabled', 'Geração automática')
                ->setSortable(false)
                ->setHelp('Permite gerar novas caixas com este domínio enquanto ele estiver dentro do prazo de validade.')
                ->setTemplatePath('admin/field/domain_generation.html.twig'),
        ];
    }
}
