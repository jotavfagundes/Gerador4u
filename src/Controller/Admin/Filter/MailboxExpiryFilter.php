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

final class MailboxExpiryFilter implements FilterInterface
{
    use FilterTrait;

    public static function new(): self
    {
        return (new self())
            ->setFilterFqcn(self::class)
            ->setProperty('expiresAt')
            ->setLabel('Prazo da caixa')
            ->setFormType(ChoiceFilterType::class)
            ->setFormTypeOption('value_type_options.choices', [
                'Ativas' => 'active',
                'Vencem em até 30 dias' => 'soon',
                'Expiradas' => 'expired',
            ]);
    }

    public function apply(QueryBuilder $queryBuilder, FilterDataDto $filterData, ?FieldDto $field, EntityDto $entity): void
    {
        $column = $filterData->getEntityAlias() . '.expiresAt';
        $now = $filterData->getParameterName();
        $soon = $now . '_soon';
        $status = $filterData->getValue();
        $predicate = match ($status) {
            'active' => "$column > :$now",
            'soon' => "$column > :$now AND $column <= :$soon",
            'expired' => "$column <= :$now",
            default => null,
        };
        if ($predicate === null) {
            return;
        }

        $date = new \DateTimeImmutable();
        $queryBuilder->setParameter($now, $date);
        if ($status === 'soon') {
            $queryBuilder->setParameter($soon, $date->modify('+30 days'));
        }
        $queryBuilder->andWhere($filterData->getComparison() === ComparisonType::NEQ
            ? "$column IS NULL OR NOT ($predicate)"
            : $predicate);
    }
}
