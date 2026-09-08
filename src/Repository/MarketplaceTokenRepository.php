<?php

declare(strict_types=1);

namespace Survos\MarketplaceBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Survos\MarketplaceBundle\Entity\MarketplaceTokenRecord;

/** @extends ServiceEntityRepository<MarketplaceTokenRecord> */
final class MarketplaceTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketplaceTokenRecord::class);
    }
}
