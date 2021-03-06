<?php

declare(strict_types=1);

namespace Brille24\SyliusCustomerOptionsPlugin\Importer;

use Brille24\SyliusCustomerOptionsPlugin\Entity\CustomerOptions\CustomerOptionValueInterface;
use Brille24\SyliusCustomerOptionsPlugin\Entity\CustomerOptions\CustomerOptionValuePriceInterface;
use Brille24\SyliusCustomerOptionsPlugin\Entity\ProductInterface;
use Brille24\SyliusCustomerOptionsPlugin\Entity\Tools\DateRange;
use Brille24\SyliusCustomerOptionsPlugin\Entity\Tools\DateRangeInterface;
use Brille24\SyliusCustomerOptionsPlugin\Exceptions\ConstraintViolationException;
use Brille24\SyliusCustomerOptionsPlugin\Factory\CustomerOptionValuePriceFactoryInterface;
use Brille24\SyliusCustomerOptionsPlugin\Object\PriceImportResult;
use Brille24\SyliusCustomerOptionsPlugin\Repository\CustomerOptionRepositoryInterface;
use Brille24\SyliusCustomerOptionsPlugin\Repository\CustomerOptionValueRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Webmozart\Assert\Assert;

class CustomerOptionPriceImporter implements CustomerOptionPriceImporterInterface
{
    protected const BATCH_SIZE = 100;

    /** @var EntityManagerInterface */
    protected $entityManager;

    /** @var ProductRepositoryInterface */
    protected $productRepository;

    /** @var ProductInterface[] */
    protected $products = [];

    /** @var ValidatorInterface */
    private $validator;

    /** @var CustomerOptionRepositoryInterface */
    private $customerOptionRepository;

    /** @var CustomerOptionValueRepositoryInterface */
    private $customerOptionValueRepository;

    /** @var ChannelRepositoryInterface */
    private $channelRepository;

    /** @var RepositoryInterface */
    private $customerOptionValuePriceRepository;

    /** @var CustomerOptionValuePriceFactoryInterface */
    private $customerOptionValuePriceFactory;

    public function __construct(
        EntityManagerInterface $entityManager,
        ProductRepositoryInterface $productRepository,
        ValidatorInterface $validator,
        CustomerOptionRepositoryInterface $customerOptionRepository,
        CustomerOptionValueRepositoryInterface $customerOptionValueRepository,
        ChannelRepositoryInterface $channelRepository,
        RepositoryInterface $customerOptionValuePriceRepository,
        CustomerOptionValuePriceFactoryInterface $customerOptionValuePriceFactory
    ) {
        $this->entityManager                      = $entityManager;
        $this->productRepository                  = $productRepository;
        $this->validator                          = $validator;
        $this->customerOptionRepository           = $customerOptionRepository;
        $this->customerOptionValueRepository      = $customerOptionValueRepository;
        $this->channelRepository                  = $channelRepository;
        $this->customerOptionValuePriceRepository = $customerOptionValuePriceRepository;
        $this->customerOptionValuePriceFactory    = $customerOptionValuePriceFactory;
    }

    /** {@inheritdoc} */
    public function import(array $data): PriceImportResult
    {
        // Handle update
        $i      = 0;
        $failed = 0;
        $errors = [];
        foreach ($data as $datum) {
            $productCode             = $datum['product_code'];
            $validFrom               = $datum['valid_from'];
            $validTo                 = $datum['valid_to'];
            $customerOptionCode      = $datum['customer_option_code'];
            $customerOptionValueCode = $datum['customer_option_value_code'];
            $channelCode             = $datum['channel_code'];
            $type                    = $datum['type'];
            $amount                  = $datum['amount'];
            $percent                 = $datum['percent'];

            try {
                $product = $this->getProduct($productCode);
                Assert::isInstanceOf(
                    $product,
                    ProductInterface::class,
                    sprintf('Product with code "%s" not found', $productCode)
                );

                // Build the date range object
                $dateRange = null;
                if (null !== $validFrom && null !== $validTo) {
                    $validFrom = new \DateTime($validFrom);
                    $validTo   = new \DateTime($validTo);
                    $dateRange = new DateRange($validFrom, $validTo);
                }

                $price = $this->getPrice($customerOptionCode, $customerOptionValueCode, $channelCode, $product, $dateRange);

                $price->setDateValid($dateRange);
                $price->setType($type);
                $price->setAmount($amount);
                $price->setPercent($percent);

                // Add the value price to the product so we can use it in the validation.
                $product->addCustomerOptionValuePrice($price);

                $violations = $this->validator->validate($product, null, 'sylius');
                if (count($violations) > 0) {
                    $product->removeCustomerOptionValuePrice($price);

                    throw new ConstraintViolationException($violations);
                }

                $this->entityManager->persist($price);

                if (++$i % self::BATCH_SIZE === 0) {
                    $this->entityManager->flush();
                }
            } catch (ConstraintViolationException $violationException) {
                ++$failed;
                $errors[$productCode][] = [
                    'violations' => $violationException->getViolations(),
                    'data'       => $datum,
                    'message'    => $violationException->getMessage(),
                ];
            } catch (\Throwable $exception) {
                ++$failed;
                $errors[$productCode][] = ['data' => $datum, 'message' => $exception->getMessage()];
            }
        }

        $this->entityManager->flush();

        return new PriceImportResult($i, $failed, $errors);
    }

    /**
     * @param string $code
     *
     * @return ProductInterface|null
     */
    private function getProduct(string $code): ?ProductInterface
    {
        if (!isset($this->products[$code])) {
            $this->products[$code] = $this->productRepository->findOneByCode($code);
        }

        return $this->products[$code];
    }

    /**
     * @param string $customerOptionCode
     * @param string $customerOptionValueCode
     * @param string $channelCode
     * @param ProductInterface $product
     * @param DateRangeInterface|null $dateRange
     *
     * @return CustomerOptionValuePriceInterface
     */
    private function getPrice(
        string $customerOptionCode,
        string $customerOptionValueCode,
        string $channelCode,
        ProductInterface $product,
        ?DateRangeInterface $dateRange
    ): CustomerOptionValuePriceInterface {
        $customerOption = $this->customerOptionRepository->findOneByCode($customerOptionCode);

        /** @var CustomerOptionValueInterface|null $customerOptionValue */
        $customerOptionValue = $this->customerOptionValueRepository->findOneBy([
            'code'           => $customerOptionValueCode,
            'customerOption' => $customerOption,
        ]);

        /** @var ChannelInterface|null $channel */
        $channel = $this->channelRepository->findOneByCode($channelCode);

        Assert::isInstanceOf(
            $customerOptionValue,
            CustomerOptionValueInterface::class,
            sprintf('CustomerOptionValue with code "%s" not found', $customerOptionValueCode)
        );
        Assert::isInstanceOf(
            $channel,
            ChannelInterface::class,
            sprintf('Channel with code "%s" not found', $channelCode)
        );

        // Try to find an existing price
        /** @var CustomerOptionValuePriceInterface[] $prices */
        $prices = $this->customerOptionValuePriceRepository->findBy([
            'customerOptionValue' => $customerOptionValue,
            'channel'             => $channel,
            'product'             => $product,
        ]);

        $valuePrice = null;
        foreach ($prices as $price) {
            $dateValid = $price->getDateValid();

            if ($dateRange === $dateValid) {
                $valuePrice = $price;
            }

            if (null !== $dateValid && null !== $dateRange && $dateValid->equals($dateRange)) {
                $valuePrice = $price;
            }
        }

        // If no price exists, create a new one
        if (null === $valuePrice) {
            // Create new price
            /** @var CustomerOptionValuePriceInterface $valuePrice */
            $valuePrice = $this->customerOptionValuePriceFactory->createNew();

            $valuePrice->setCustomerOptionValue($customerOptionValue);
            $valuePrice->setChannel($channel);

            if (null !== $product) {
                $valuePrice->setProduct($product);
            }
        }

        return $valuePrice;
    }
}
