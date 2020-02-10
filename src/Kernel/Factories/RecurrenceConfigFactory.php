<?php

namespace Mundipagg\Core\Kernel\Factories;

use Mundipagg\Core\Kernel\Abstractions\AbstractEntity;
use Mundipagg\Core\Kernel\Interfaces\FactoryCreateFromDbDataInterface;
use Mundipagg\Core\Kernel\ValueObjects\Configuration\RecurrenceConfig;

class RecurrenceConfigFactory implements FactoryCreateFromDbDataInterface
{
    /**
     * @param array $data
     * @return AbstractEntity|RecurrenceConfig
     */
    public function createFromDbData($data)
    {

        $recurrenceConfig = new RecurrenceConfig();

        if (isset($data->enabled)) {
            $recurrenceConfig->setEnabled((bool) $data->enabled);
        }

        if (isset($data->showRecurrenceCurrencyWidget)) {
            $recurrenceConfig->setShowRecurrenceCurrencyWidget(
                (bool) $data->showRecurrenceCurrencyWidget
            );
        }

        if (isset($data->purchaseRecurrenceProductWithNormalProduct)) {
            $recurrenceConfig->setPurchaseRecurrenceProductWithNormalProduct(
                (bool) $data->purchaseRecurrenceProductWithNormalProduct
            );
        }

        if (isset($data->conflictMessageRecurrenceProductWithNormalProduct)) {
            $recurrenceConfig->setConflictMessageRecurrenceProductWithNormalProduct(
                $data->conflictMessageRecurrenceProductWithNormalProduct
            );
        }

        if (isset($data->purchaseRecurrenceProductWithRecurrenceProduct)) {
            $recurrenceConfig->setPurchaseRecurrenceProductWithRecurrenceProduct(
                $data->purchaseRecurrenceProductWithRecurrenceProduct
            );
        }

        if (isset($data->conflictMessageRecurrenceProductWithRecurrenceProduct)) {
            $recurrenceConfig->setConflictMessageRecurrenceProductWithRecurrenceProduct(
                $data->conflictMessageRecurrenceProductWithRecurrenceProduct
            );
        }

        return $recurrenceConfig;
    }
}
