<?php

/*
 * This file is part of the Sylius package.
 *
 * (c) Sylius Sp. z o.o.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Sylius\Component\Core\Taxation\Resolver;

use Sylius\Component\Addressing\Model\ZoneInterface;
use Sylius\Component\Addressing\Repository\ZoneRepositoryInterface;
use Sylius\Component\Core\Model\Scope;
use Sylius\Component\Taxation\Model\TaxableInterface;
use Sylius\Component\Taxation\Model\TaxRateInterface;
use Sylius\Component\Taxation\Resolver\TaxRateResolverInterface;

/**
 * When no tax rate is found for the given zone, looks for one in the zones consisting of it,
 * the nearest ones first and, on the same level, the ones with the higher priority first.
 */
final class ZoneHierarchyAwareTaxRateResolver implements TaxRateResolverInterface
{
    /** @param ZoneRepositoryInterface<ZoneInterface> $zoneRepository */
    public function __construct(
        private TaxRateResolverInterface $decoratedTaxRateResolver,
        private ZoneRepositoryInterface $zoneRepository,
    ) {
    }

    public function resolve(TaxableInterface $taxable, array $criteria = []): ?TaxRateInterface
    {
        $taxRate = $this->decoratedTaxRateResolver->resolve($taxable, $criteria);

        $zone = $criteria['zone'] ?? null;
        if (null !== $taxRate || null === $taxable->getTaxCategory() || !$zone instanceof ZoneInterface) {
            return $taxRate;
        }

        $visitedZoneCodes = [(string) $zone->getCode()];
        $zones = [$zone];

        while ([] !== $zones = $this->findParentZones($zones, $visitedZoneCodes)) {
            foreach ($zones as $parentZone) {
                $criteria['zone'] = $parentZone;

                $taxRate = $this->decoratedTaxRateResolver->resolve($taxable, $criteria);
                if (null !== $taxRate) {
                    return $taxRate;
                }
            }
        }

        return null;
    }

    /**
     * @param array<ZoneInterface> $zones
     * @param array<string> $visitedZoneCodes
     *
     * @return array<ZoneInterface>
     */
    private function findParentZones(array $zones, array &$visitedZoneCodes): array
    {
        $parentZones = [];

        foreach ($this->zoneRepository->findByMembers($zones, Scope::TAX) as $parentZone) {
            $code = (string) $parentZone->getCode();
            if (in_array($code, $visitedZoneCodes, true)) {
                continue;
            }

            $visitedZoneCodes[] = $code;
            $parentZones[] = $parentZone;
        }

        usort(
            $parentZones,
            static fn (ZoneInterface $firstZone, ZoneInterface $secondZone): int => $secondZone->getPriority() <=> $firstZone->getPriority(),
        );

        return $parentZones;
    }
}
