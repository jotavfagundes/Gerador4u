<?php

namespace App\Controller\Admin\Filter;

use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Filter\FilterInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FieldDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FilterDataDto;
use EasyCorp\Bundle\EasyAdminBundle\Filter\FilterTrait;
use EasyCorp\Bundle\EasyAdminBundle\Form\Filter\Type\ChoiceFilterType;
use EasyCorp\Bundle\EasyAdminBundle\Form\Type\ComparisonType;

final class MailboxDomainFilter implements FilterInterface
{
    use FilterTrait;

    public static function new(array $choices): self
    {
        return (new self())
            ->setFilterFqcn(self::class)
            ->setProperty('emailDomain')
            ->setLabel('Domínio')
            ->setFormType(ChoiceFilterType::class)
            ->setFormTypeOption('value_type_options.choices', $choices);
    }

    public function apply(QueryBuilder $queryBuilder, FilterDataDto $filterData, ?FieldDto $field, EntityDto $entity): void
    {
        $domain = $filterData->getValue();
        if (!is_string($domain) || !preg_match('/^[a-z0-9.-]+$/iD', $domain)) {
            return;
        }

        $alias = $filterData->getEntityAlias();
        $parameter = $filterData->getParameterName();
        $operator = $filterData->getComparison() === ComparisonType::NEQ ? 'NOT LIKE' : 'LIKE';
        $queryBuilder->andWhere("LOWER($alias.email) $operator :$parameter")
            ->setParameter($parameter, '%@' . strtolower($domain));
    }
}
