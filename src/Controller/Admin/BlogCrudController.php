<?php

namespace App\Controller\Admin;

use App\Entity\Blog;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\SlugField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class BlogCrudController extends AbstractCrudController
{
    public function __construct(private readonly string $displayTimezone)
    {
    }

    public static function getEntityFqcn(): string
    {
        return Blog::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->setDisabled(),
            TextField::new('title', 'Título'),
            SlugField::new('slug', 'Slug')->setTargetFieldName('title'),
            ImageField::new('photo', 'Imagem')
                ->setBasePath('uploads/images/blogs/')
                ->setUploadDir('public/uploads/images/blogs/')
                ->setUploadedFileNamePattern('[randomhash].[extension]')
                ->setRequired(false),
            TextEditorField::new('shortDescription', 'Resumo'),
            TextEditorField::new('description', 'Descrição'),
            DateTimeField::new('createdAt', 'Criado em')
                ->setFormTypeOption('view_timezone', $this->displayTimezone),
        ];
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setDefaultSort(['createdAt' => 'DESC']);
    }
}
