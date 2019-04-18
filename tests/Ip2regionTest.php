<?php

use Bas\Ip2region\Ip2region as Ip;
use PHPUnit\Framework\TestCase;

class Ip2regionTest extends TestCase
{

    private $ip;

    protected function setUp()
    {
        $this->ip = '27.115.98.150';
    }

    public function testGetRegionInfoByIp()
    {
        $ret = Ip::getRegionInfoByIp($this->ip);
        $this->assertEquals('156|310000|0', $ret);
    }

    public function testGetRegionCodeByIp()
    {
        $code = Ip::getRegionCodeByIp($this->ip);
        $this->assertEquals('310000', $code);
    }

    public function testGetRegionNameByIp()
    {
        $name = Ip::getRegionNameByIp($this->ip);
        $pos = strpos($name, '上海');

        $this->assertNotFalse($pos);
    }
}
