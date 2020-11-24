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

namespace Brille24\SyliusCustomerOptionsPlugin\Form;

use Brille24\SyliusCustomerOptionsPlugin\Enumerations\CustomerOptionTypeEnum;
use DateTime;
use DateTimeZone;
use Sylius\Bundle\MoneyBundle\Formatter\MoneyFormatterInterface;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Currency\Context\CurrencyContextInterface;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

final class CustomerOptionConfigurationType extends AbstractType
{
    /** @var ChannelContextInterface */
    private $channelContext;

    /** @var CurrencyContextInterface */
    private $currencyContext;

    /** @var MoneyFormatterInterface */
    private $moneyFormatter;

    /** @var LocaleContextInterface */
    private $localeContext;

    public function __construct(
        ChannelContextInterface $channelContext,
        CurrencyContextInterface $currencyContext,
        MoneyFormatterInterface $moneyFormatter,
        LocaleContextInterface $localeContext
    ) {
        $this->channelContext = $channelContext;
        $this->currencyContext = $currencyContext;
        $this->moneyFormatter = $moneyFormatter;
        $this->localeContext = $localeContext;
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        
        $channelContext = $this->channelContext;
        $builder->addEventListener(FormEvents::PRE_SET_DATA,  function (FormEvent $event) use ($channelContext){
            $form = $event->getForm();
            $configuration = $event->getData();

            foreach ($configuration as $key => $configArray) {
                $type = $configArray['type'];
                $data = $configArray['value'];

                // Transforming Datetime objects
                if (CustomerOptionTypeEnum::isDate($type)) {
                    $data = new DateTime($data['date'], new DateTimeZone($data['timezone']));
                }

                [$formTypeClass, $formTypeConfig] = CustomerOptionTypeEnum::getFormTypeArray($channelContext->getChannel())[$type];

                // Adding form field for configuration option based on type
                $form->add(
                    str_replace('.', ':', $key),
                    $formTypeClass,
                    array_merge(['data' => $data, 'label' => $key], $formTypeConfig)
                );
            }
        });
    }

    /**
     * {@inheritdoc}
     */
    public function getBlockPrefix(): string
    {
        return 'brille24_customer_option_configuration';
    }
}
