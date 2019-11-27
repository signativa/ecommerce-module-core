<?php

namespace Mundipagg\Core\Recurrence\Repositories;

use Exception;
use Mundipagg\Core\Kernel\Abstractions\AbstractDatabaseDecorator;
use Mundipagg\Core\Kernel\Abstractions\AbstractEntity;
use Mundipagg\Core\Kernel\Abstractions\AbstractRepository;
use Mundipagg\Core\Kernel\Exceptions\InvalidParamException;
use Mundipagg\Core\Kernel\ValueObjects\AbstractValidString;
use Mundipagg\Core\Recurrence\Aggregates\Subscription;
use Mundipagg\Core\Recurrence\Factories\SubscriptionFactory;

class SubscriptionRepository extends AbstractRepository
{
    /**
     * @param AbstractValidString $mundipaggId
     * @return AbstractEntity|Subscription|null
     * @throws InvalidParamException
     */
    public function findByMundipaggId(AbstractValidString $mundipaggId)
    {
        $chargeTable = $this->db->getTable(AbstractDatabaseDecorator::TABLE_RECURRENCE_SUBSCRIPTION);
        $id = $mundipaggId->getValue();

        $query = "
            SELECT *
              FROM $chargeTable as recurrence_subscription                  
             WHERE recurrence_subscription.mundipagg_id = '{$id}'             
        ";

        $result = $this->db->fetch($query);
        if ($result->num_rows === 0) {
            return null;
        }

        $factory = new SubscriptionFactory();
        return $factory->createFromDbData($result->row);
    }

    /**
     * @param Subscription|AbstractEntity $object
     * @throws Exception
     */
    protected function create(AbstractEntity &$object)
    {
        $subscriptionTable = $this->db->getTable(AbstractDatabaseDecorator::TABLE_RECURRENCE_SUBSCRIPTION);

        $query = "
          INSERT INTO 
            $subscriptionTable 
            (
                mundipagg_id, 
                code,                 
                status,
                installments,
                payment_method,
                recurrence_type,
                interval_type,
                interval_count
            )
          VALUES 
        ";

        $query .= "
            (
                '{$object->getMundipaggId()->getValue()}',                
                '{$object->getCode()}',
                '{$object->getStatus()->getStatus()}',                
                '{$object->getInstallments()}',
                '{$object->getPaymentMethod()->getPaymentMethod()}',             
                '{$object->getRecurrenceType()}',       
                '{$object->getIntervalType()->getIntervalType()}',       
                '{$object->getIntervalType()->getIntervalCount()}'       
            );
        ";

        $this->db->query($query);
    }

    /**
     * @param Subscription|AbstractEntity $object
     * @throws Exception
     */
    protected function update(AbstractEntity &$object)
    {
        $subscriptionTable = $this->db->getTable(AbstractDatabaseDecorator::TABLE_RECURRENCE_SUBSCRIPTION);

        $query = "
            UPDATE {$subscriptionTable} SET
              mundipagg_id = '{$object->getMundipaggId()->getValue()}',
              code = '{$object->getCode()}',                         
              status = '{$object->getStatus()->getStatus()}',
              installments = '{$object->getInstallments()}',
              payment_method = '{$object->getPaymentMethod()->getPaymentMethod()}',
              recurrence_type = '{$object->getRecurrenceType()}',
              interval_type = '{$object->getIntervalType()->getIntervalType()}',
              interval_count = '{$object->getIntervalType()->getIntervalCount()}'
            WHERE id = {$object->getId()}
        ";

        $this->db->query($query);
    }

    public function delete(AbstractEntity $object)
    {
        // TODO: Implement delete() method.
    }

    public function find($objectId)
    {
        // TODO: Implement find() method.
    }

    /**
     * @param $limit
     * @param $listDisabled
     * @return Subscription[]|array
     * @throws InvalidParamException
     */
    public function listEntities($limit, $listDisabled)
    {
        $table =
            $this->db->getTable(AbstractDatabaseDecorator::TABLE_RECURRENCE_SUBSCRIPTION);

        $query = "SELECT * FROM `$table` as t";

        if ($limit !== 0) {
            $limit = intval($limit);
            $query .= " LIMIT $limit";
        }

        $result = $this->db->fetch($query . ";");

        $factory = new SubscriptionFactory();

        $listSubscription = [];
        foreach ($result->rows as $row) {
            $subscription = $factory->createFromDBData($row);
            $listSubscription[] = $subscription;
        }

        return $listSubscription;
    }

    /**
     * @param $customerId
     * @return AbstractEntity|Subscription[]|null
     * @throws InvalidParamException
     */
    public function findByCustomerId($customerId)
    {
        $recurrenceTable = $this->db->getTable(AbstractDatabaseDecorator::TABLE_RECURRENCE_SUBSCRIPTION);

        $query = "
            SELECT *
              FROM {$recurrenceTable} as recurrence_subscription                  
             WHERE recurrence_subscription.customer_id = '{$customerId}'             
        ";

        $result = $this->db->fetch($query);
        if ($result->num_rows === 0) {
            return null;
        }

        $factory = new SubscriptionFactory();

        $listSubscription = [];
        foreach ($result->rows as $row) {
            $subscription = $factory->createFromDBData($row);
            $listSubscription[] = $subscription;
        }

        return $listSubscription;
    }
}
