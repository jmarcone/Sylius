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

namespace Tests\Sylius\Component\Core\Taxation\Resolver;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Addressing\Model\ZoneInterface;
use Sylius\Component\Addressing\Repository\ZoneRepositoryInterface;
use Sylius\Component\Core\Model\Scope;
use Sylius\Component\Core\Taxation\Resolver\ZoneHierarchyAwareTaxRateResolver;
use Sylius\Component\Taxation\Model\TaxableInterface;
use Sylius\Component\Taxation\Model\TaxCategoryInterface;
use Sylius\Component\Taxation\Model\TaxRateInterface;
use Sylius\Component\Taxation\Resolver\TaxRateResolverInterface;

#[AllowMockObjectsWithoutExpectations]
final class ZoneHierarchyAwareTaxRateResolverTest extends TestCase
{
    private MockObject&TaxRateResolverInterface $decoratedTaxRateResolver;

    /** @var MockObject&ZoneRepositoryInterface<ZoneInterface> */
    private MockObject&ZoneRepositoryInterface $zoneRepository;

    private MockObject&TaxableInterface $taxable;

    private ZoneHierarchyAwareTaxRateResolver $resolver;

    protected function setUp(): void
    {
        $this->decoratedTaxRateResolver = $this->createMock(TaxRateResolverInterface::class);
        $this->zoneRepository = $this->createMock(ZoneRepositoryInterface::class);
        $this->taxable = $this->createMock(TaxableInterface::class);
        $this->taxable->method('getTaxCategory')->willReturn($this->createMock(TaxCategoryInterface::class));

        $this->resolver = new ZoneHierarchyAwareTaxRateResolver($this->decoratedTaxRateResolver, $this->zoneRepository);
    }

    public function testShouldImplementTaxRateResolverInterface(): void
    {
        $this->assertInstanceOf(TaxRateResolverInterface::class, $this->resolver);
    }

    public function testShouldReturnTaxRateOfGivenZoneWithoutLookingForParentZones(): void
    {
        $zone = $this->createZone('EU');
        $taxRate = $this->createMock(TaxRateInterface::class);

        $this->decoratedTaxRateResolver
            ->expects($this->once())
            ->method('resolve')
            ->with($this->taxable, ['zone' => $zone])
            ->willReturn($taxRate)
        ;
        $this->zoneRepository->expects($this->never())->method('findByMembers');

        $this->assertSame($taxRate, $this->resolver->resolve($this->taxable, ['zone' => $zone]));
    }

    public function testShouldNotLookForParentZonesWhenNoZoneIsGiven(): void
    {
        $this->decoratedTaxRateResolver->expects($this->once())->method('resolve')->with($this->taxable, [])->willReturn(null);
        $this->zoneRepository->expects($this->never())->method('findByMembers');

        $this->assertNull($this->resolver->resolve($this->taxable));
    }

    public function testShouldNotLookForParentZonesWhenTaxableHasNoTaxCategory(): void
    {
        $taxable = $this->createMock(TaxableInterface::class);
        $taxable->method('getTaxCategory')->willReturn(null);
        $zone = $this->createZone('EU');

        $this->decoratedTaxRateResolver->expects($this->once())->method('resolve')->willReturn(null);
        $this->zoneRepository->expects($this->never())->method('findByMembers');

        $this->assertNull($this->resolver->resolve($taxable, ['zone' => $zone]));
    }

    public function testShouldReturnTaxRateOfZoneConsistingOfGivenZoneWhenGivenZoneHasNoTaxRate(): void
    {
        $zone = $this->createZone('EUROPE');
        $parentZone = $this->createZone('ALL_ZONES');
        $taxRate = $this->createMock(TaxRateInterface::class);

        $this->zoneRepository
            ->expects($this->once())
            ->method('findByMembers')
            ->with([$zone], Scope::TAX)
            ->willReturn([$parentZone])
        ;
        $this->decoratedTaxRateResolver
            ->expects($this->exactly(2))
            ->method('resolve')
            ->willReturnCallback(fn (TaxableInterface $taxable, array $criteria): ?TaxRateInterface => match ($criteria['zone']) {
                $parentZone => $taxRate,
                default => null,
            })
        ;

        $this->assertSame($taxRate, $this->resolver->resolve($this->taxable, ['zone' => $zone]));
    }

    public function testShouldPassOtherCriteriaWhenLookingForTaxRateOfParentZone(): void
    {
        $zone = $this->createZone('EUROPE');
        $parentZone = $this->createZone('ALL_ZONES');
        $taxRate = $this->createMock(TaxRateInterface::class);

        $this->zoneRepository->method('findByMembers')->willReturn([$parentZone]);
        $this->decoratedTaxRateResolver
            ->method('resolve')
            ->willReturnCallback(fn (TaxableInterface $taxable, array $criteria): ?TaxRateInterface => $criteria === ['zone' => $parentZone, 'includedInPrice' => true] ? $taxRate : null)
        ;

        $this->assertSame($taxRate, $this->resolver->resolve($this->taxable, ['zone' => $zone, 'includedInPrice' => true]));
    }

    public function testShouldLookForTaxRateFurtherUpTheZoneHierarchy(): void
    {
        $zone = $this->createZone('LITHUANIA');
        $parentZone = $this->createZone('BALTIC_STATES');
        $grandparentZone = $this->createZone('EU');
        $taxRate = $this->createMock(TaxRateInterface::class);

        $this->zoneRepository
            ->expects($this->exactly(2))
            ->method('findByMembers')
            ->willReturnCallback(fn (array $members, ?string $scope): array => match ($members) {
                [$zone] => [$parentZone],
                [$parentZone] => [$grandparentZone],
                default => [],
            })
        ;
        $this->decoratedTaxRateResolver
            ->expects($this->exactly(3))
            ->method('resolve')
            ->willReturnCallback(fn (TaxableInterface $taxable, array $criteria): ?TaxRateInterface => match ($criteria['zone']) {
                $grandparentZone => $taxRate,
                default => null,
            })
        ;

        $this->assertSame($taxRate, $this->resolver->resolve($this->taxable, ['zone' => $zone]));
    }

    public function testShouldPreferParentZoneWithHigherPriority(): void
    {
        $zone = $this->createZone('LITHUANIA');
        $lowerPriorityParentZone = $this->createZone('ALL_ZONES', 1);
        $higherPriorityParentZone = $this->createZone('EU_OSS', 2);
        $lowerPriorityTaxRate = $this->createMock(TaxRateInterface::class);
        $higherPriorityTaxRate = $this->createMock(TaxRateInterface::class);

        $this->zoneRepository->method('findByMembers')->willReturn([$lowerPriorityParentZone, $higherPriorityParentZone]);
        $this->decoratedTaxRateResolver
            ->method('resolve')
            ->willReturnCallback(fn (TaxableInterface $taxable, array $criteria): ?TaxRateInterface => match ($criteria['zone']) {
                $lowerPriorityParentZone => $lowerPriorityTaxRate,
                $higherPriorityParentZone => $higherPriorityTaxRate,
                default => null,
            })
        ;

        $this->assertSame($higherPriorityTaxRate, $this->resolver->resolve($this->taxable, ['zone' => $zone]));
    }

    public function testShouldReturnNullWhenNoZoneInHierarchyHasTaxRateEvenIfZonesContainEachOther(): void
    {
        $zone = $this->createZone('FIRST');
        $parentZone = $this->createZone('SECOND');

        $this->zoneRepository
            ->expects($this->exactly(2))
            ->method('findByMembers')
            ->willReturnCallback(fn (array $members, ?string $scope): array => match ($members) {
                [$zone] => [$parentZone],
                [$parentZone] => [$zone],
                default => [],
            })
        ;
        $this->decoratedTaxRateResolver->expects($this->exactly(2))->method('resolve')->willReturn(null);

        $this->assertNull($this->resolver->resolve($this->taxable, ['zone' => $zone]));
    }

    private function createZone(string $code, int $priority = 0): MockObject&ZoneInterface
    {
        $zone = $this->createMock(ZoneInterface::class);
        $zone->method('getCode')->willReturn($code);
        $zone->method('getPriority')->willReturn($priority);

        return $zone;
    }
}
