<?php

/**
 * This file is part of the Brille24 customer options plugin.
 *
 * (c) Brille24 GmbH
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
declare(strict_types=1);

namespace Brille24\SyliusCustomerOptionsPlugin\Factory;

use Brille24\SyliusCustomerOptionsPlugin\Entity\CustomerOptions\CustomerOptionInterface;
use Brille24\SyliusCustomerOptionsPlugin\Entity\CustomerOptions\CustomerOptionValueInterface;
use Brille24\SyliusCustomerOptionsPlugin\Entity\OrderItemOptionInterface;
use Brille24\SyliusCustomerOptionsPlugin\Entity\ProductInterface;
use Brille24\SyliusCustomerOptionsPlugin\Enumerations\CustomerOptionTypeEnum;
use Brille24\SyliusCustomerOptionsPlugin\Repository\CustomerOptionRepositoryInterface;
use Brille24\SyliusCustomerOptionsPlugin\Services\CustomerOptionValueResolverInterface;
use Exception;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;

class OrderItemOptionFactory implements OrderItemOptionFactoryInterface, FactoryInterface
{
    /**
     * @var FactoryInterface
     */
    private $factory;

    /**
     * @var ChannelContextInterface
     */
    private $channelContext;

    /**
     * @var CustomerOptionRepositoryInterface
     */
    private $customerOptionRepository;

    /**
     * @var CustomerOptionValueResolverInterface
     */
    private $valueResolver;

    public function __construct(
        FactoryInterface $factory,
        ChannelContextInterface $channelContext,
        CustomerOptionRepositoryInterface $customerOptionRepository,
        CustomerOptionValueResolverInterface $valueResolver
    ) {
        $this->factory = $factory;
        $this->channelContext = $channelContext;
        $this->customerOptionRepository = $customerOptionRepository;
        $this->valueResolver = $valueResolver;
    }

    /** {@inheritdoc} */
    public function createNew(): object
    {
        return $this->factory->createNew();
    }

    /** {@inheritdoc} */
    public function createForOptionAndValue(CustomerOptionInterface $customerOption, $customerOptionValue): OrderItemOptionInterface
    {
        /** @var OrderItemOptionInterface $orderItemOption */
        $orderItemOption = $this->createNew();

        $orderItemOption->setCustomerOption($customerOption);
        $orderItemOption->setCustomerOptionValue($customerOptionValue);

        if ($customerOptionValue instanceof CustomerOptionValueInterface) {
            /** @var ChannelInterface $channel */
            $channel = $this->channelContext->getChannel();
            $price = $customerOptionValue->getPriceForChannel($channel);
            $orderItemOption->setPrice($price);
        }

        return $orderItemOption;
    }

    /** {@inheritdoc} */
    public function createForOptionAndValueAndProduct(CustomerOptionInterface $customerOption, $customerOptionValue, ProductInterface $product): OrderItemOptionInterface
    {
        /** @var OrderItemOptionInterface $orderItemOption */
        $orderItemOption = $this->createNew();

        $orderItemOption->setCustomerOption($customerOption);
        $orderItemOption->setCustomerOptionValue($customerOptionValue);

        if ($customerOptionValue instanceof CustomerOptionValueInterface) {
            /** @var ChannelInterface $channel */
            $channel = $this->channelContext->getChannel();
            $price = $customerOptionValue->getPriceForChannel($channel, false, $product);
            $orderItemOption->setPrice($price);
        }

        return $orderItemOption;
    }

    /** {@inheritdoc} */
    public function createNewFromStrings(
        string $customerOptionCode,
        string $customerOptionValue
    ): OrderItemOptionInterface {
        $customerOption = $this->customerOptionRepository->findOneByCode($customerOptionCode);
        if ($customerOption === null) {
            throw new Exception('Could not find customer option with code');
        }

        if (CustomerOptionTypeEnum::isSelect($customerOption->getType())) {
            $customerOptionValue = $this->valueResolver->resolve($customerOption, $customerOptionValue);
        }

        return $this->createForOptionAndValue($customerOption, $customerOptionValue);
    }

    /** {@inheritdoc} */
    public function createNewFromStringsForProduct(
        string $customerOptionCode,
        string $customerOptionValue,
        ProductInterface $product
    ): OrderItemOptionInterface {
        $customerOption = $this->customerOptionRepository->findOneByCode($customerOptionCode);
        if ($customerOption === null) {
            throw new Exception('Could not find customer option with code');
        }

        if (CustomerOptionTypeEnum::isSelect($customerOption->getType())) {
            $customerOptionValue = $this->valueResolver->resolve($customerOption, $customerOptionValue);
        }
        $orderItemOption = $this->createForOptionAndValueAndProduct($customerOption, $customerOptionValue, $product);
       

        return $orderItemOption;
    }
}
