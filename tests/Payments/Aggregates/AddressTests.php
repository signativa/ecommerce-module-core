<?php


namespace Mundipagg\Core\Test\Payments\Aggregates;

use Mundipagg\Core\Payment\Aggregates\Address;
use PHPUnit\Framework\TestCase;

class AddressTests extends TestCase
{
    /**
     * @var Address
     */
    private $andress;

    public function setUp()
    {
        $this->andress = new Address();
    }

    public function testsAddressNumberRemoveComma()
    {
        $this->andress->setNumber('12,3,4,5,6');
        $this->assertEquals('123456', $this->andress->getNumber());
    }
}